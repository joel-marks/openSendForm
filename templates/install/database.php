<?php
use function OpenSendForm\Admin\h;

/**
 * @var string $csrf
 * @var string $error
 * @var string $driver
 * @var string $dbHost
 * @var string $dbPort
 * @var string $dbName
 * @var string $dbUser
 */
$driver = $driver === 'mysql' ? 'mysql' : 'sqlite';
?>
<h1>Where should submissions be stored?</h1>

<p>
    OpenSendForm needs a database to keep your forms and the submissions people
    send. If you’re not sure, choose the <strong>built-in database</strong> —
    it needs nothing else and works on almost every host.
</p>

<?php if (($error ?? '') !== ''): ?>
    <p class="osf-flash osf-flash--error" role="alert"><strong><?= h($error) ?></strong></p>
<?php endif; ?>

<form method="post" action="/install/database">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <fieldset>
        <legend><strong>Database type</strong></legend>
        <div class="osf-field">
            <label>
                <input type="radio" name="db_driver" value="sqlite" data-db-driver
                       <?= $driver === 'sqlite' ? 'checked' : '' ?>>
                Built-in database (SQLite) — recommended, nothing to set up.
            </label>
        </div>
        <div class="osf-field">
            <label>
                <input type="radio" name="db_driver" value="mysql" data-db-driver
                       <?= $driver === 'mysql' ? 'checked' : '' ?>>
                MySQL database — only if your host told you to use one.
            </label>
        </div>
    </fieldset>

    <?php /* Visible by default so it works with JavaScript OFF (the current
             always-visible layout). install.js hides it while SQLite is
             selected and shows it when MySQL is chosen. */ ?>
    <section data-mysql-details>
        <h2>MySQL details</h2>
        <p><small>Fill these in only if you chose MySQL above. You create a
            database (and its username and password) in cPanel under
            “MySQL® Databases”.</small></p>

        <div class="osf-field">
            <label for="db_host">Database host</label>
            <input type="text" id="db_host" name="db_host" value="<?= h($dbHost) ?>"
                   placeholder="localhost" autocomplete="off">
        </div>

        <div class="osf-field">
            <label for="db_port">Port</label>
            <input type="text" id="db_port" name="db_port" value="<?= h($dbPort) ?>"
                   placeholder="3306" inputmode="numeric" autocomplete="off">
        </div>

        <div class="osf-field">
            <label for="db_name">Database name</label>
            <input type="text" id="db_name" name="db_name" value="<?= h($dbName) ?>" autocomplete="off">
        </div>

        <div class="osf-field">
            <label for="db_user">Database username</label>
            <input type="text" id="db_user" name="db_user" value="<?= h($dbUser) ?>" autocomplete="off">
        </div>

        <div class="osf-field">
            <label for="db_pass">Database password</label>
            <input type="password" id="db_pass" name="db_pass" autocomplete="off">
        </div>
    </section>

    <div class="osf-actions osf-step-actions">
        <button type="submit">Test and continue</button>
    </div>
</form>
