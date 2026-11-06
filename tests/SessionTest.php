<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    private array $savedSession;
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedServer = $_SERVER ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $_SERVER = $this->savedServer;
    }

    public function testSessionTokenIsRandomHex(): void
    {
        $token = createSessionToken();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testHashSessionTokenIsSha256(): void
    {
        $this->assertSame(hash('sha256', 'abc'), hashSessionToken('abc'));
    }

    public function testRegisterUniqueSessionHashesTokenAndStoresSessionId(): void
    {
        $db = new FakeSessionDb();
        $token = 'token-secreto-123';
        registerUniqueSession($db, 7, $token);

        $this->assertCount(1, $db->inserts);
        $params = $db->inserts[0];
        $this->assertSame(7, $params[':usuario_id']);
        $this->assertSame(hash('sha256', $token), $params[':session_token_hash']);
        $this->assertSame((int) SESSION_ABSOLUTE_LIFETIME, $params[':expires']);
    }

    public function testInvalidateUniqueSessionUpdatesByToken(): void
    {
        $db = new FakeSessionDb();
        invalidateUniqueSession($db, 7, 'token-x');

        $this->assertCount(1, $db->updates);
        $this->assertSame(hash('sha256', 'token-x'), $db->updates[0][':session_token_hash']);
        $this->assertSame(7, $db->updates[0][':usuario_id']);
    }

    public function testInvalidateUniqueSessionWithoutTokenRevokesAll(): void
    {
        $db = new FakeSessionDb();
        invalidateUniqueSession($db, 7);

        $this->assertCount(1, $db->updates);
        $this->assertSame(7, $db->updates[0][':usuario_id']);
        $this->assertArrayNotHasKey(':session_token_hash', $db->updates[0]);
    }

    public function testSessionValidWhenActiveNotExpiredAndHashMatches(): void
    {
        $token = 'token-ok';
        $row = (object) [
            'active' => 1,
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'session_token_hash' => hash('sha256', $token),
        ];
        $db = new FakeSessionDb([$row]);
        $this->assertTrue(isUniqueSessionValid($db, 7, $token));
    }

    public function testSessionInvalidWhenExpired(): void
    {
        $token = 'token-expirado';
        $row = (object) [
            'active' => 1,
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
            'session_token_hash' => hash('sha256', $token),
        ];
        $db = new FakeSessionDb([$row]);
        $this->assertFalse(isUniqueSessionValid($db, 7, $token));
    }

    public function testSessionInvalidWhenInactive(): void
    {
        $token = 'token-inativo';
        $row = (object) [
            'active' => 0,
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'session_token_hash' => hash('sha256', $token),
        ];
        $db = new FakeSessionDb([$row]);
        $this->assertFalse(isUniqueSessionValid($db, 7, $token));
    }

    public function testSessionInvalidWhenRevoked(): void
    {
        $token = 'token-revogado';
        $row = (object) [
            'active' => 1,
            'revoked_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'session_token_hash' => hash('sha256', $token),
        ];
        $db = new FakeSessionDb([$row]);
        $this->assertFalse(isUniqueSessionValid($db, 7, $token));
    }

    public function testSessionInvalidWhenTokenMismatch(): void
    {
        $row = (object) [
            'active' => 1,
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'session_token_hash' => hash('sha256', 'outro-token'),
        ];
        $db = new FakeSessionDb([$row]);
        $this->assertFalse(isUniqueSessionValid($db, 7, 'token-errado'));
    }

    public function testSessionInvalidWhenNoToken(): void
    {
        $db = new FakeSessionDb();
        $this->assertFalse(isUniqueSessionValid($db, 7, null));
    }

    public function testSessionInvalidWhenNoRow(): void
    {
        $db = new FakeSessionDb([]);
        $this->assertFalse(isUniqueSessionValid($db, 7, 'token'));
    }

    public function testPurgeExpiredSessionsDeletesOnlyExpired(): void
    {
        $db = new FakeSessionDb();
        purgeExpiredSessions($db);
        $this->assertCount(1, $db->deletes);
        $this->assertStringContainsString('expires_at < NOW()', $db->deletes[0]['sql']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFinalizeLoginSessionRegeneratesIdAndSetsUsuario(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $oldId = session_id();
        $db = new FakeSessionDb();
        $usuario = (object) ['id' => 7, 'nome' => 'Joao', 'nivel_acesso' => 'user', 'senha' => 'hash-nao-deve-ficar'];

        $error = null;
        finalizeLoginSession($db, $usuario, function () use (&$error) {
            $error = true;
        });

        $this->assertNull($error);
        $this->assertSame('Joao', $_SESSION['usuario']->nome);
        $this->assertObjectNotHasProperty('senha', $_SESSION['usuario']);
        $this->assertNotEmpty($_SESSION['session_token']);
        $this->assertNotSame($oldId, session_id());
        $this->assertCount(1, $db->inserts);
        $this->assertSame(7, $db->inserts[0][':usuario_id']);
    }
}

class FakeSessionDb extends database
{
    public array $inserts = [];
    public array $updates = [];
    public array $deletes = [];

    public function __construct(private array $rows = [])
    {
    }

    public function select(string $sql, array $params = []): array
    {
        return ['status' => 'success', 'data' => $this->rows];
    }

    public function insert(string $sql, array $params = []): array
    {
        $this->inserts[] = $params;
        return ['status' => 'success'];
    }

    public function update(string $sql, array $params = []): array
    {
        $this->updates[] = $params;
        return ['status' => 'success'];
    }

    public function delete(string $sql, array $params = []): array
    {
        $this->deletes[] = ['sql' => $sql, ...$params];
        return ['status' => 'success'];
    }
}