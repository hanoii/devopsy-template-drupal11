<?php

/**
 * @file
 * Drupal settings for devopsy: everything comes from the environment, set in
 * .devopsy/compose.yaml. See default.settings.php for every available setting.
 */

$databases['default']['default'] = [
  'driver' => 'mysql',
  'host' => getenv('DB_HOST'),
  'database' => getenv('DB_NAME'),
  'username' => getenv('DB_USER'),
  'password' => getenv('DB_PASSWORD'),
  'port' => 3306,
  'prefix' => '',
  'isolation_level' => 'READ COMMITTED',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT');

// It must exist (config/sync/.gitkeep): the installer cannot create it in the
// read-only image, and asks for database settings again without it.
$settings['config_sync_directory'] = dirname(DRUPAL_ROOT) . '/config/sync';

// Public files are a symlink to /app/storage/public (see the Dockerfile).
$settings['file_private_path'] = '/app/storage/private';
$settings['file_temp_path'] = '/tmp';

// Compiled Twig is PHP that Drupal includes. By default it is written to
// public://php, which persists across releases, so code running as the app
// could leave PHP there. /tmp is the container's tmpfs, rebuilt at each
// start. drush runs in the same container, so `drush cr` clears what the web
// server compiled.
$settings['php_storage']['default']['directory'] = '/tmp/php';

// The code is read-only: no module or theme installs from the UI.
$settings['update_free_access'] = FALSE;
$settings['allow_authorize_operations'] = FALSE;

// Traefik only routes this environment's hosts (its router rule), so the
// Host header is already restricted.
$settings['trusted_host_patterns'] = ['.*'];

// Requests only reach the app through devopsy-traefik, over Docker networks,
// and Traefik drops X-Forwarded-* headers from untrusted peers. So the peer is
// always Traefik: trust it for the client IP and HTTPS.
if (PHP_SAPI !== 'cli' && !empty($_SERVER['REMOTE_ADDR'])) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR']];
}

$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['state_cache'] = TRUE;

if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}

// Automatically generated include for settings managed by ddev.
$ddev_settings = __DIR__ . '/settings.ddev.php';
if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($ddev_settings)) {
  require $ddev_settings;
}
