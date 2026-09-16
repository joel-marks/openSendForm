<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

use InvalidArgumentException;
use OpenSendForm\Storage\Database;

/**
 * Data-access layer for administrators.
 *
 * Passwords are hashed here (never stored plaintext), TOTP secrets and
 * recovery-code hashes are persisted per admin, and all access goes through
 * prepared statements on the Database wrapper. Recovery-code consumption is
 * delegated to RecoveryCodes but the read/verify/write cycle lives here so
 * callers deal in "consume code X for admin Y" terms.
 */
final class AdminRepository
{
    private Database $db;
    private PasswordHasher $hasher;
    private RecoveryCodes $recoveryCodes;

    public function __construct(Database $db, PasswordHasher $hasher, RecoveryCodes $recoveryCodes)
    {
        $this->db = $db;
        $this->hasher = $hasher;
        $this->recoveryCodes = $recoveryCodes;
    }

    /**
     * Create an administrator, hashing the supplied password.
     *
     * @return array<string, mixed> The created admin (hydrated).
     *
     * @throws InvalidArgumentException on a bad email or empty display name.
     */
    public function createAdmin(string $email, string $displayName, string $password): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid admin email: {$email}");
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new InvalidArgumentException('Admin display name must not be empty.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Admin password must not be empty.');
        }

        $now = self::now();

        $this->db->execute(
            'INSERT INTO admins
                (email, display_name, password_hash, totp_secret, totp_enabled,
                 recovery_codes, created_at, updated_at, last_login_at)
             VALUES
                (:email, :display_name, :password_hash, NULL, 0,
                 NULL, :created_at, :updated_at, NULL)',
            [
                'email'         => $email,
                'display_name'  => $displayName,
                'password_hash' => $this->hasher->hash($password),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]
        );

        $id = (int) $this->db->pdo()->lastInsertId();

        $admin = $this->findById($id);
        if ($admin === null) {
            // Should be unreachable: we just inserted the row.
            throw new \RuntimeException('Failed to load admin after creation.');
        }

        return $admin;
    }

    /**
     * Every admin, oldest first — the single-tenant admins list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM admins ORDER BY id ASC');

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * How many admins are currently active. Used to enforce the guard that
     * the last active admin can never be deactivated.
     */
    public function countActive(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM admins WHERE is_active = 1');

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Activate or deactivate an admin. Returns true when a row was changed.
     */
    public function setActive(int $id, bool $active): bool
    {
        $this->db->execute(
            'UPDATE admins SET is_active = :active, updated_at = :now WHERE id = :id',
            ['active' => $active ? 1 : 0, 'now' => self::now(), 'id' => $id]
        );

        return $this->findById($id) !== null;
    }

    /**
     * Replace an admin's display name (no re-authentication required).
     *
     * @throws InvalidArgumentException on an empty name.
     */
    public function updateDisplayName(int $id, string $displayName): void
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new InvalidArgumentException('Admin display name must not be empty.');
        }

        $this->db->execute(
            'UPDATE admins SET display_name = :name, updated_at = :now WHERE id = :id',
            ['name' => $displayName, 'now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Replace an admin's email after validating and normalising it. Uniqueness
     * is the caller's concern (checked against findByEmail) so a friendly
     * message can be shown; the UNIQUE index is the backstop.
     *
     * @throws InvalidArgumentException on an invalid email.
     */
    public function updateEmail(int $id, string $email): void
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid admin email: {$email}");
        }

        $this->db->execute(
            'UPDATE admins SET email = :email, updated_at = :now WHERE id = :id',
            ['email' => $email, 'now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Find an admin by email (case-insensitive), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM admins WHERE email = :email',
            ['email' => strtolower(trim($email))]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Find an admin by id, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM admins WHERE id = :id',
            ['id' => $id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Stamp a successful login time.
     */
    public function recordLogin(int $id): void
    {
        $now = self::now();
        $this->db->execute(
            'UPDATE admins SET last_login_at = :now, updated_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $id]
        );
    }

    /**
     * Replace an admin's password with a freshly hashed one.
     */
    public function updatePassword(int $id, string $newPassword): void
    {
        $this->updatePasswordHash($id, $this->hasher->hash($newPassword));
    }

    /**
     * Store an already-computed password hash. Used by the opportunistic
     * rehash path, which hashes the plaintext it already holds at login.
     */
    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->db->execute(
            'UPDATE admins SET password_hash = :hash, updated_at = :now WHERE id = :id',
            ['hash' => $hash, 'now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Store a TOTP secret without enabling enforcement yet (enrolment step).
     */
    public function setTotp(int $id, string $secret): void
    {
        $this->db->execute(
            'UPDATE admins SET totp_secret = :secret, updated_at = :now WHERE id = :id',
            ['secret' => $secret, 'now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Turn on TOTP enforcement for an admin.
     */
    public function enableTotp(int $id): void
    {
        $this->db->execute(
            'UPDATE admins SET totp_enabled = 1, updated_at = :now WHERE id = :id',
            ['now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Turn off TOTP and clear its secret, recovery codes AND the replay marker.
     * Clearing totp_last_timestep means a subsequent fresh enrolment starts with
     * a clean slate (any timestep's first code is accepted), and `admin:reset-2fa`
     * — which calls this — leaves no stale replay state behind.
     */
    public function disableTotp(int $id): void
    {
        $this->db->execute(
            'UPDATE admins
                SET totp_enabled = 0, totp_secret = NULL, recovery_codes = NULL,
                    totp_last_timestep = NULL, updated_at = :now
              WHERE id = :id',
            ['now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Record the RFC 6238 counter (timestep) of the TOTP code just accepted for
     * an admin, so it can never be replayed within its validity window. Callers
     * only ever advance this forwards (they accept a code solely when it matches
     * a strictly larger timestep).
     */
    public function recordTotpTimestep(int $id, int $timestep): void
    {
        $this->db->execute(
            'UPDATE admins SET totp_last_timestep = :ts, updated_at = :now WHERE id = :id',
            ['ts' => $timestep, 'now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Store a fresh set of recovery-code hashes (JSON array).
     *
     * @param array<int, string> $hashes
     */
    public function setRecoveryCodes(int $id, array $hashes): void
    {
        $this->db->execute(
            'UPDATE admins SET recovery_codes = :codes, updated_at = :now WHERE id = :id',
            ['codes' => $this->recoveryCodes->encode($hashes), 'now' => self::now(), 'id' => $id]
        );
    }

    /**
     * Consume one recovery code for an admin. Returns true on a match (after
     * persisting the reduced set), false otherwise. Single-use is enforced by
     * removing the matched hash before it is written back.
     */
    public function consumeRecoveryCode(int $id, string $code): bool
    {
        $admin = $this->findById($id);
        if ($admin === null) {
            return false;
        }

        $hashes = $this->recoveryCodes->decode($admin['recovery_codes']);
        $remaining = $this->recoveryCodes->consume($code, $hashes);
        if ($remaining === null) {
            return false;
        }

        $this->db->execute(
            'UPDATE admins SET recovery_codes = :codes, updated_at = :now WHERE id = :id',
            ['codes' => $this->recoveryCodes->encode($remaining), 'now' => self::now(), 'id' => $id]
        );

        return true;
    }

    /**
     * Permanently remove an admin. Returns true when a row was deleted.
     *
     * Callers (AdminsController, `bin/osf admin:delete`) are responsible for
     * the availability/self-delete guards; this method performs the delete
     * unconditionally once called.
     */
    public function deleteAdmin(int $id): bool
    {
        $stmt = $this->db->execute('DELETE FROM admins WHERE id = :id', ['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cast a raw row into typed PHP values.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'email'          => (string) $row['email'],
            'display_name'   => (string) $row['display_name'],
            'password_hash'  => (string) $row['password_hash'],
            'totp_secret'    => isset($row['totp_secret']) && $row['totp_secret'] !== null
                ? (string) $row['totp_secret'] : null,
            'totp_enabled'   => (int) $row['totp_enabled'],
            'recovery_codes' => isset($row['recovery_codes']) && $row['recovery_codes'] !== null
                ? (string) $row['recovery_codes'] : null,
            'totp_last_timestep' => isset($row['totp_last_timestep']) && $row['totp_last_timestep'] !== null
                ? (int) $row['totp_last_timestep'] : null,
            'is_active'      => (int) ($row['is_active'] ?? 1),
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
            'last_login_at'  => isset($row['last_login_at']) && $row['last_login_at'] !== null
                ? (string) $row['last_login_at'] : null,
        ];
    }

    /**
     * Current UTC timestamp in a portable, lexicographically-sortable form.
     */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
