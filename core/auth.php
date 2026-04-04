<?php
/**
 * FORGE | Sovereign Access Lock
 *
 * Cookie-based auth — avoids PHP session GC cron wiping sessions every 24 min.
 * Token is a deterministic HMAC of the access key, stored in a 1-year cookie.
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Load key from gitignored credentials file, fallback to env var
$_creds_file = __DIR__ . '/credentials.env';
$ACCESS_KEY  = file_exists($_creds_file)
    ? trim(file_get_contents($_creds_file))
    : (getenv('FORGE_ACCESS_KEY') ?: '');

$AUTH_TOKEN  = hash_hmac('sha256', 'forge_auth_v1', $ACCESS_KEY);
$COOKIE_NAME = 'forge_auth';
$COOKIE_TTL  = 365 * 24 * 3600; // 1 year

if (isset($_GET['logout'])) {
    setcookie($COOKIE_NAME, '', time() - 3600, '/', '', false, true);
    header("Location: /forge/");
    exit;
}

$authenticated = isset($_COOKIE[$COOKIE_NAME]) && hash_equals($AUTH_TOKEN, $_COOKIE[$COOKIE_NAME]);

if (!$authenticated) {
    if (isset($_POST['access_key']) && $_POST['access_key'] === $ACCESS_KEY) {
        setcookie($COOKIE_NAME, $AUTH_TOKEN, time() + $COOKIE_TTL, '/', '', false, true);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
        <title>FORGE | Locked</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #050508; color: #e0e0e0; font-family: 'Inter', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
            .lock-card { background: #0a0a0a; border: 2px solid #222; padding: 3rem 2rem; width: 100%; max-width: 400px; text-align: center; border-radius: 4px; }
            .brand { font-weight: 900; letter-spacing: -2px; font-size: 2.5rem; margin-bottom: 2rem; }
            .brand span { color: #FF5722; }
            .form-control { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; text-align: center; font-family: monospace; letter-spacing: 4px; font-size: 16px !important; }
            .btn-forge { background: #FF5722; color: #fff; font-weight: 900; border: none; width: 100%; margin-top: 1rem; padding: 14px; font-size: 1rem; letter-spacing: 1px; }
            .btn-forge:active { background: #E64A19; }
        </style>
    </head>
    <body>
        <div class="lock-card">
            <div class="brand"><span>FORGE</span></div>
            <form method="POST">
                <div class="mb-3">
                    <label class="small fw-bold mb-2 d-block" style="text-transform:uppercase; letter-spacing:1px; color:#888;">Access Key</label>
                    <input type="password" name="access_key" class="form-control form-control-lg" autofocus autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-forge text-uppercase">Unlock</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}
// $authenticated === true beyond this point
?>
