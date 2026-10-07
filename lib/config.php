<?php
/*
 * Default settings. Don't edit this file on the server: copy the keys you want
 * to change into config.local.php at the site root, for example:
 *
 *   <?php
 *   return [
 *       'data_dir'  => '/home/ntgroup/ntmart-go-data',
 *       'setup_key' => 'a-long-random-phrase',
 *   ];
 */

return [
    // Where the client database and installer files live. Best outside the
    // web root; the default is the site's data/ folder, which data/.htaccess
    // closes to visitors on Apache (XAMPP, cPanel).
    'data_dir' => dirname(__DIR__) . '/data',

    // Creating the first admin account needs this key. Leave it empty to turn
    // account creation off once your admin account exists.
    'setup_key' => '',

    // Sign admins out after this many minutes without activity.
    'admin_idle_minutes' => 30,

    // How long a download link works after a client enters their code.
    'download_link_minutes' => 30,
];
