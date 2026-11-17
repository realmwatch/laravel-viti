<?php

namespace Realmwatch\Viti\Support;

use Illuminate\Support\Str;

/**
 * Fake secrets that look like this site's own: a production .env, a
 * mysqldump, a comment left in a template.
 *
 * Everything is derived from the site's secret seed (VITI_BAIT_SEED), so a
 * site always serves the same bait, while two sites differ in their values
 * and in the shape of the output: key order, which optional keys and tables
 * appear, versions, wording. The source being public gives a scanner no
 * fixed string or layout to search for.
 */
final class BaitGenerator
{
    private const ALNUM = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const HEX = '0123456789abcdef';

    private readonly string $host;

    public function __construct(
        private readonly string $seed,
        private readonly string $appName = 'Laravel',
        private readonly string $appUrl = 'http://localhost',
        private readonly ?string $dbHost = null,
    ) {
        $this->host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: 'localhost');
    }

    /**
     * Identifies this site's bait set without revealing it: the sha256 of
     * the bait DB_PASSWORD. A decoy database host can hash a password it
     * sees and match it back to the site that leaked it.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->dbPassword());
    }

    public function env(): string
    {
        $db = $this->db();
        $mail = $this->mail();
        $groups = [
            'log' => [
                'LOG_CHANNEL' => 'stack',
                'LOG_STACK' => $this->maybe('log.stack', 'single'),
                'LOG_DEPRECATIONS_CHANNEL' => $this->maybe('log.dep', 'null'),
                'LOG_LEVEL' => $this->pick('log.level', ['error', 'warning', 'debug', 'info']),
            ],
            'db' => [
                'DB_CONNECTION' => $db['driver'],
                'DB_HOST' => $db['host'],
                'DB_PORT' => '3306',
                'DB_DATABASE' => $db['database'],
                'DB_USERNAME' => $db['username'],
                'DB_PASSWORD' => $this->dbPassword(),
            ],
            'session' => [
                'SESSION_DRIVER' => $this->pick('session.driver', ['database', 'redis', 'file']),
                'SESSION_LIFETIME' => $this->pick('session.lifetime', ['120', '120', '240', '480']),
                'SESSION_ENCRYPT' => $this->maybe('session.encrypt', 'false'),
                'SESSION_PATH' => $this->maybe('session.path', '/'),
                'SESSION_DOMAIN' => $this->maybe('session.domain', 'null'),
                'BROADCAST_CONNECTION' => $this->maybe('broadcast', 'log'),
                'FILESYSTEM_DISK' => $this->pick('disk', ['local', 'local', 's3']),
                'QUEUE_CONNECTION' => $this->pick('queue', ['redis', 'database', 'sync']),
                'CACHE_STORE' => $this->pick('cache', ['redis', 'database', 'file']),
            ],
            'redis' => $this->chance('redis', 70) ? [
                'REDIS_CLIENT' => $this->maybe('redis.client', 'phpredis'),
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PASSWORD' => $this->chance('redis.pw', 30) ? $this->chars('redis.pass', 32, self::ALNUM) : 'null',
                'REDIS_PORT' => '6379',
            ] : [],
            'mail' => [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => $mail['host'],
                'MAIL_PORT' => $mail['port'],
                'MAIL_USERNAME' => $mail['username'],
                'MAIL_PASSWORD' => $mail['password'],
                'MAIL_ENCRYPTION' => $this->maybe('mail.tls', 'tls'),
                'MAIL_FROM_ADDRESS' => '"'.$this->pick('mail.from', ['hello', 'noreply', 'no-reply', 'support', 'info']).'@'.$this->domain().'"',
                'MAIL_FROM_NAME' => '"${APP_NAME}"',
            ],
            'aws' => $this->chance('aws', 50) ? [
                'AWS_ACCESS_KEY_ID' => 'AKIA'.$this->chars('aws.id', 16, self::UPPER),
                'AWS_SECRET_ACCESS_KEY' => $this->chars('aws.secret', 40, self::ALNUM.'+/'),
                'AWS_DEFAULT_REGION' => $this->pick('aws.region', ['us-east-1', 'us-east-2', 'us-west-2', 'eu-west-1']),
                'AWS_BUCKET' => Str::slug($this->appName).'-'.$this->pick('aws.bucket', ['uploads', 'production', 'assets', 'files']),
                'AWS_USE_PATH_STYLE_ENDPOINT' => $this->maybe('aws.path', 'false'),
            ] : [],
            'stripe' => [
                'STRIPE_KEY' => 'pk_live_51'.$this->chars('stripe.pk', 97, self::ALNUM),
                'STRIPE_SECRET' => 'sk_live_51'.$this->chars('stripe.sk', 97, self::ALNUM),
                'STRIPE_WEBHOOK_SECRET' => $this->chance('stripe.wh', 80) ? 'whsec_'.$this->chars('stripe.whsec', 32, self::ALNUM) : null,
                'CASHIER_CURRENCY' => $this->maybe('stripe.currency', 'usd'),
            ],
            'sentry' => $this->chance('sentry', 40) ? [
                'SENTRY_LARAVEL_DSN' => 'https://'.$this->chars('sentry.key', 32, self::HEX).'@o'.$this->int('sentry.org', 100000, 4999999)
                    .'.ingest.us.sentry.io/'.$this->int('sentry.project', 4000000, 4599999),
                'SENTRY_TRACES_SAMPLE_RATE' => $this->maybe('sentry.rate', $this->pick('sentry.rate.v', ['0.1', '0.2', '1.0'])),
            ] : [],
        ];

        // Laravel's own groups keep their usual order; what was added later
        // (Stripe, Sentry) lands wherever whoever added it put it.
        $tail = $this->shuffle('order', ['stripe', 'sentry']);
        $order = ['log', 'db', 'session', 'redis', 'mail', 'aws'];
        foreach ($tail as $name) {
            array_splice($order, $this->int("order.{$name}", 2, count($order)), 0, [$name]);
        }

        $out = [$this->lines([
            'APP_NAME' => $this->quote($this->appName),
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode($this->bytes('app.key', 32)),
            'APP_DEBUG' => 'false',
            'APP_TIMEZONE' => $this->maybe('app.tz', 'UTC'),
            'APP_URL' => $this->appUrl,
        ])];
        if ($this->chance('app.locale', 60)) {
            $out[] = $this->lines(['APP_LOCALE' => 'en', 'APP_FALLBACK_LOCALE' => 'en', 'APP_FAKER_LOCALE' => 'en_US']);
        }
        if ($this->chance('app.bcrypt', 50)) {
            $out[] = $this->lines(['BCRYPT_ROUNDS' => '12']);
        }
        foreach ($order as $name) {
            if ($groups[$name] !== []) {
                $out[] = $this->lines($groups[$name]);
            }
        }
        if ($this->chance('vite', 70)) {
            $out[] = $this->lines(['VITE_APP_NAME' => '"${APP_NAME}"']);
        }

        return implode("\n\n", $out)."\n";
    }

    /** A mysqldump of a small Laravel database. */
    public function sql(): string
    {
        $db = $this->db();
        $mariadb = $db['driver'] === 'mariadb';
        $version = $mariadb ? '10.11.'.$this->int('sql.ver', 6, 11).'-MariaDB' : '8.0.'.$this->int('sql.ver', 35, 42);
        $collation = $mariadb || $this->chance('sql.coll', 50) ? 'utf8mb4_unicode_ci' : 'utf8mb4_0900_ai_ci';
        $created = $this->int('sql.created', 1704067200, 1748736000);
        $dumped = $this->modifiedAt('sql');

        $tables = [];

        $migrations = ['0001_01_01_000000_create_users_table', '0001_01_01_000001_create_cache_table', '0001_01_01_000002_create_jobs_table'];
        if ($this->chance('sql.pat', 60)) {
            $migrations[] = gmdate('Y_m_d_His', $created + 3600).'_create_personal_access_tokens_table';
        }
        $tables['migrations'] = $this->table('migrations', [
            '`id` int unsigned NOT NULL AUTO_INCREMENT',
            '`migration` varchar(255) COLLATE '.$collation.' NOT NULL',
            '`batch` int NOT NULL',
            'PRIMARY KEY (`id`)',
        ], $collation, array_map(fn ($m, $i) => [$i + 1, $m, 1], $migrations, array_keys($migrations)));

        $users = [];
        $count = $this->int('sql.users', 1, 3);
        $roles = $this->shuffle('sql.roles', [['Admin', 'admin'], ['Support', 'support'], ['Operations', 'ops'], ['Site Admin', 'webmaster'], ['IT', 'it']]);
        for ($i = 0; $i < $count; $i++) {
            $at = gmdate('Y-m-d H:i:s', $created + $i * $this->int("sql.u{$i}.gap", 3600, intdiv($dumped - $created, 3)));
            $users[] = [$i + 1, $roles[$i][0], $roles[$i][1].'@'.$this->domain(), $at,
                '$2y$12$'.$this->chars("sql.u{$i}.pw", 53, './'.self::ALNUM),
                $this->chance("sql.u{$i}.rt", 50) ? $this->chars("sql.u{$i}.rt", 60, self::ALNUM) : null, $at, $at];
        }
        $tables['users'] = $this->table('users', [
            '`id` bigint unsigned NOT NULL AUTO_INCREMENT',
            '`name` varchar(255) COLLATE '.$collation.' NOT NULL',
            '`email` varchar(255) COLLATE '.$collation.' NOT NULL',
            '`email_verified_at` timestamp NULL DEFAULT NULL',
            '`password` varchar(255) COLLATE '.$collation.' NOT NULL',
            '`remember_token` varchar(100) COLLATE '.$collation.' DEFAULT NULL',
            '`created_at` timestamp NULL DEFAULT NULL',
            '`updated_at` timestamp NULL DEFAULT NULL',
            'PRIMARY KEY (`id`)',
            'UNIQUE KEY `users_email_unique` (`email`)',
        ], $collation, $users);

        if (count($migrations) > 3) {
            $at = gmdate('Y-m-d H:i:s', $dumped - $this->int('sql.pat.age', 3600, 86400 * 20));
            $tables['personal_access_tokens'] = $this->table('personal_access_tokens', [
                '`id` bigint unsigned NOT NULL AUTO_INCREMENT',
                '`tokenable_type` varchar(255) COLLATE '.$collation.' NOT NULL',
                '`tokenable_id` bigint unsigned NOT NULL',
                '`name` varchar(255) COLLATE '.$collation.' NOT NULL',
                '`token` varchar(64) COLLATE '.$collation.' NOT NULL',
                '`abilities` text COLLATE '.$collation,
                '`last_used_at` timestamp NULL DEFAULT NULL',
                '`expires_at` timestamp NULL DEFAULT NULL',
                '`created_at` timestamp NULL DEFAULT NULL',
                '`updated_at` timestamp NULL DEFAULT NULL',
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `personal_access_tokens_token_unique` (`token`)',
                'KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)',
            ], $collation, [[1, 'App\\Models\\User', 1, $this->pick('sql.pat.name', ['api', 'deploy', 'integration', 'zapier']),
                $this->chars('sql.pat.token', 64, self::HEX), '["*"]', $at, null, $at, $at]]);
        }

        ksort($tables); // mysqldump goes alphabetically

        $product = $mariadb ? 'MariaDB dump 10.19  Distrib '.$version.', for debian-linux-gnu (x86_64)' : 'MySQL dump 10.13  Distrib '.$version.', for Linux (x86_64)';

        return '-- '.$product."\n--\n-- Host: ".$db['host'].'    Database: '.$db['database']."\n"
            ."-- ------------------------------------------------------\n-- Server version\t".$version."\n\n"
            ."/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
            ."/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n"
            ."/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n"
            ."/*!50503 SET NAMES utf8mb4 */;\n"
            ."/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
            ."/*!40103 SET TIME_ZONE='+00:00' */;\n"
            ."/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n"
            ."/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
            ."/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            ."/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n"
            .implode('', $tables)
            ."/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n\n"
            ."/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
            ."/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
            ."/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
            ."/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
            ."/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
            ."/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n"
            ."/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n\n"
            .'-- Dump completed on '.gmdate('Y-m-d H:i:s', $dumped)."\n";
    }

    /** When the bait file was last written, for its Last-Modified header: 'env' or 'sql'. */
    public function modifiedAt(string $file): int
    {
        return $file === 'sql'
            ? $this->int('sql.created', 1704067200, 1748736000) + $this->int('sql.age', 86400 * 30, 86400 * 400)
            : $this->int("mtime.{$file}", 1717200000, 1767225600);
    }

    /** An HTML comment that points at $path, in this site's own wording. */
    public function comment(string $path): string
    {
        $area = $this->pick('crumb.area', ['admin', 'old admin', 'staff panel', 'backoffice', 'ops dashboard', 'internal tools', 'legacy admin', 'support console', 'reports']);
        $when = $this->pick('crumb.when', ['before launch', 'after the migration', 'once SSO is live', 'after the cutover', 'next release', 'when v2 ships']);
        $text = str_replace(['{area}', '{when}', '{path}'], [$area, $when, $path], $this->pick('crumb.text', [
            '{area} moved to {path}',
            '{area}: {path} ({when})',
            'remove {path} link {when}',
            'keep {path} out of the nav until {when}',
            '{area} is still at {path}, delete {when}',
            'temp: {area} -> {path}',
            'link to {path} hidden {when}',
            '{path} = {area}',
        ]));
        $lead = $this->pick('crumb.lead', ['', '', 'TODO: ', 'todo: ', 'FIXME: ', 'NOTE: ', 'XXX ']);
        if ($this->chance('crumb.cap', 40)) {
            $text = ucfirst($text);
        }

        return '<!-- '.$lead.$text.' -->';
    }

    /** @return array{driver: string, host: string, database: string, username: string} */
    private function db(): array
    {
        $slug = Str::snake(Str::slug($this->appName, ' ')) ?: 'app';

        return [
            'driver' => $this->pick('db.driver', ['mysql', 'mysql', 'mysql', 'mariadb']),
            'host' => $this->dbHost ?: $this->pick('db.host', [
                '127.0.0.1', '127.0.0.1', 'localhost',
                '10.'.$this->int('db.ip.b', 0, 30).'.'.$this->int('db.ip.c', 0, 255).'.'.$this->int('db.ip.d', 4, 250),
            ]),
            'database' => $this->pick('db.name', ['forge', $slug, $slug.'_production', $slug]),
            'username' => $this->pick('db.user', ['forge', 'forge', $slug]),
        ];
    }

    private function dbPassword(): string
    {
        return $this->chars('db.password', $this->int('db.password.len', 20, 40), self::ALNUM);
    }

    /** @return array{host: string, port: string, username: string, password: string} */
    private function mail(): array
    {
        $uuid = implode('-', array_map(fn ($n, $i) => $this->chars("mail.pm.{$i}", $n, self::HEX), [8, 4, 4, 4, 12], range(0, 4)));

        return match ($this->pick('mail.provider', ['mailgun', 'postmark', 'sendgrid', 'ses'])) {
            'mailgun' => ['host' => 'smtp.mailgun.org', 'port' => '587', 'username' => 'postmaster@mg.'.$this->domain(),
                'password' => $this->chars('mail.mg', 32, self::HEX).'-'.$this->chars('mail.mg2', 8, self::HEX).'-'.$this->chars('mail.mg3', 8, self::HEX)],
            'postmark' => ['host' => 'smtp.postmarkapp.com', 'port' => '587', 'username' => $uuid, 'password' => $uuid],
            'sendgrid' => ['host' => 'smtp.sendgrid.net', 'port' => '587', 'username' => 'apikey',
                'password' => 'SG.'.$this->chars('mail.sg', 22, self::ALNUM.'-_').'.'.$this->chars('mail.sg2', 43, self::ALNUM.'-_')],
            default => ['host' => 'email-smtp.'.$this->pick('mail.ses.region', ['us-east-1', 'us-west-2']).'.amazonaws.com', 'port' => '587',
                'username' => 'AKIA'.$this->chars('mail.ses.id', 16, self::UPPER), 'password' => 'B'.$this->chars('mail.ses.pw', 43, self::ALNUM.'+/')],
        };
    }

    private function domain(): string
    {
        return (string) preg_replace('/^www\./', '', $this->host);
    }

    /** @param array<string, ?string> $pairs */
    private function lines(array $pairs): string
    {
        $lines = [];
        foreach ($pairs as $key => $value) {
            if ($value !== null) {
                $lines[] = $key.'='.$value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function table(string $name, array $columns, string $collation, array $rows): string
    {
        $values = implode(',', array_map(fn ($row) => '('.implode(',', array_map(
            fn ($v) => match (true) {
                $v === null => 'NULL',
                is_int($v) => (string) $v,
                default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v)."'",
            }, $row)).')', $rows));

        return "--\n-- Table structure for table `{$name}`\n--\n\n"
            ."DROP TABLE IF EXISTS `{$name}`;\n"
            ."/*!40101 SET @saved_cs_client     = @@character_set_client */;\n"
            ."/*!50503 SET character_set_client = utf8mb4 */;\n"
            ."CREATE TABLE `{$name}` (\n  ".implode(",\n  ", $columns)."\n"
            .') ENGINE=InnoDB AUTO_INCREMENT='.(count($rows) + 1).' DEFAULT CHARSET=utf8mb4 COLLATE='.$collation.";\n"
            ."/*!40101 SET character_set_client = @saved_cs_client */;\n\n"
            ."--\n-- Dumping data for table `{$name}`\n--\n\n"
            ."LOCK TABLES `{$name}` WRITE;\n"
            ."/*!40000 ALTER TABLE `{$name}` DISABLE KEYS */;\n"
            ."INSERT INTO `{$name}` VALUES {$values};\n"
            ."/*!40000 ALTER TABLE `{$name}` ENABLE KEYS */;\n"
            ."UNLOCK TABLES;\n\n";
    }

    private function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.\-\/:]*$/', $value) ? $value : '"'.addcslashes($value, '"\\').'"';
    }

    // The seeded randomness. Each draw has its own label, so the same seed
    // always gives the same bait, and adding a draw never shifts the others.

    private function bytes(string $label, int $n): string
    {
        $out = '';
        for ($i = 0; strlen($out) < $n; $i++) {
            $out .= hash_hmac('sha256', $label."\0".$i, $this->seed, true);
        }

        return substr($out, 0, $n);
    }

    private function int(string $label, int $min, int $max): int
    {
        return $min + unpack('N', $this->bytes($label, 4))[1] % ($max - $min + 1);
    }

    private function chance(string $label, int $percent): bool
    {
        return $this->int($label, 1, 100) <= $percent;
    }

    private function maybe(string $label, string $value): ?string
    {
        return $this->chance($label, 60) ? $value : null;
    }

    /**
     * @template T
     *
     * @param  list<T>  $options
     * @return T
     */
    private function pick(string $label, array $options): mixed
    {
        return $options[$this->int($label, 0, count($options) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function shuffle(string $label, array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int("{$label}.{$i}", 0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    private function chars(string $label, int $n, string $alphabet): string
    {
        $len = strlen($alphabet);

        return implode('', array_map(fn ($b) => $alphabet[$b % $len], unpack('C*', $this->bytes($label, $n))));
    }
}
