<?php
/** Apply only the additive, repeatable runtime repairs; never replay legacy ALTER migrations. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/helpers.php';
load_env(BASE_PATH . '/.env');
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

try {
    $db = \App\Core\Database::instance();

    // Bootstrap the base schema before additive runtime repairs. This is
    // idempotent and is required for a fresh external database because later
    // migrations reference tables such as customers and users.
    $db->pdo()->exec(file_get_contents(BASE_PATH . '/database/schema.sql'));
    echo "Ready: database/schema.sql\n";

    foreach (['010_flexible_article_transactions.sql', '012_persistent_product_media.sql'] as $migration) {
        foreach (explode(';', file_get_contents(BASE_PATH . '/database/migrations/' . $migration)) as $sql) {
            if (trim($sql) !== '') $db->pdo()->exec($sql);
        }
        echo "Ready: {$migration}\n";
    }
    $media = new \App\Services\ProductMedia();
    foreach ($db->all('SELECT path, thumb_path FROM product_images') as $image) {
        foreach (array_unique(array_filter($image)) as $path) {
            $file = BASE_PATH . '/public/uploads/' . $path;
            if ($media::validPath($path) && is_file($file) && !$media->exists($path)) {
                $media->store($path, $file);
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Runtime upgrade failed: {$e->getMessage()}\n");
    exit(1);
}
