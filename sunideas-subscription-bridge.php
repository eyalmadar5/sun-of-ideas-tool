<?php
/**
 * Plugin Name: Sun of Ideas - Subscription Bridge
 * Description: מקבל Webhooks מ-Grow (תשלומים/מנויים), מנהל גישת משתמשים לכלי, ומפיק חשבוניות דרך קארדקום.
 * Version: 1.0
 * Author: שמש הרעיונות
 */

if (!defined('ABSPATH')) { exit; } // אין גישה ישירה לקובץ

// נקודת התנתקות "ישירה" משלנו - מדלגת לגמרי על מסך האישור המובנה של
// וורדפרס ("Do you really want to log out?"), כדי שלחיצה אחת תתנתק
// ותחזיר ישר לדף הבית, בלי שלב ביניים. (הערת אבטחה: זה מוותר על הגנת
// ה-CSRF שהמנגנון הרגיל של וורדפרס נותן, בתמורה לחוויה חלקה יותר - כאן
// זה סיכון נמוך, כי הפעולה היחידה שאפשר "לכפות" היא ניתוק, לא חשיפת מידע).
// חשוב: נקודת קצה אחת וברורה בלבד לניתוק+הפניה - קודם היו שתיים (גם hook
// על wp_logout וגם כאן) שיכלו להתנגש זו בזו ולעצור את הניקוי באמצע.
add_action('init', function () {
    if (isset($_GET['sunideas_logout']) && is_user_logged_in()) {
        nocache_headers(); // מונע מכל שכבת קאש (LiteSpeed וכו') להתערב בתגובת ההתנתקות עצמה
        wp_destroy_current_session();
        wp_clear_auth_cookie();
        wp_set_current_user(0);
        wp_safe_redirect(home_url());
        exit;
    }
}, 1); // עדיפות מוקדמת - רץ לפני כל קוד אחר שעלול לשלוח פלט/כותרות מוקדם מדי

// ============================================================
// בדיקה יומית: חוסמת אוטומטית משתמשים שתוקף המנוי שלהם פג
// (רשת ביטחון - תופסת גם ביטולים ש-Grow לא בהכרח שולחת עליהם
// Webhook מפורש, כי פשוט אין חיוב הבא, ואין "כישלון" להתריע עליו)
// ============================================================
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('sunideas_daily_expiry_check')) {
        wp_schedule_event(time(), 'daily', 'sunideas_daily_expiry_check');
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('sunideas_daily_expiry_check');
});
add_action('sunideas_daily_expiry_check', function () {
    $users = get_users([
        'meta_key'   => 'sunideas_subscription_active',
        'meta_value' => '1',
    ]);
    $now = time();
    $deactivated = 0;
    foreach ($users as $u) {
        $expiry = (int) get_user_meta($u->ID, 'sunideas_subscription_expiry', true);
        if ($expiry > 0 && $expiry < $now) {
            update_user_meta($u->ID, 'sunideas_subscription_active', '0');
            $deactivated++;
        }
    }
    if ($deactivated > 0) {
        sunideas_log_event('בדיקה יומית', '-', "נחסמו {$deactivated} משתמשים שתוקף המנוי שלהם פג");
    }
});

// ============================================================
// הגדרות - ממלאים דרך מסך ההגדרות באדמין (לא בקוד!)
// ============================================================
define('SUNIDEAS_OPTION_GROUP', 'sunideas_bridge_settings');

// ============================================================
// מסך הגדרות באדמין - להזנת פרטים סודיים בבטחה
// ============================================================
add_action('admin_menu', function () {
    add_options_page(
        'הגדרות שמש הרעיונות',
        'שמש הרעיונות',
        'manage_options',
        'sunideas-bridge',
        'sunideas_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_webhook_secret');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_monthly_price');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_annual_monthly_price');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_yearly_full_price');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_twoyear_full_price');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_cardcom_terminal');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_cardcom_username');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_cardcom_api_password');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_tool_page_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_require_active_subscription');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_tool_iframe_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_home_page_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_home_iframe_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_signup_page_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_signup_iframe_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_login_page_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_login_iframe_url');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_history_secret');
    register_setting(SUNIDEAS_OPTION_GROUP, 'sunideas_google_client_id');
});

function sunideas_render_settings_page() {
    $webhook_url = rest_url('sunideas/v1/grow-webhook');
    ?>
    <div class="wrap">
        <h1>הגדרות שמש הרעיונות - חיבור מנויים</h1>

        <div class="notice notice-info">
            <p><strong>כתובת ה-Webhook להזנה ב-Grow:</strong><br>
            <code><?php echo esc_html($webhook_url); ?>?key=<?php echo esc_html(get_option('sunideas_webhook_secret', '(הזינו והזינו מפתח סודי למטה קודם)')); ?></code></p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields(SUNIDEAS_OPTION_GROUP); ?>
            <table class="form-table">
                <tr>
                    <th><label for="sunideas_webhook_secret">מפתח סודי ל-Webhook</label></th>
                    <td>
                        <input type="text" id="sunideas_webhook_secret" name="sunideas_webhook_secret"
                               value="<?php echo esc_attr(get_option('sunideas_webhook_secret')); ?>" class="regular-text">
                        <p class="description">מחרוזת אקראית שתמציאו (למשל: <?php echo esc_html(wp_generate_password(24, false)); ?>) - זו הגנה שרק Grow עם המפתח הזה יוכל "לעורר" את החיבור. הזינו אותה גם כאן וגם ב-Grow (בשדה "פרמטר מזהה" ב-Webhook, או כחלק מכתובת ה-Notify URL כמו שמוצג למעלה).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sunideas_history_secret">מפתח סודי לזיהוי משתמש בכלי</label></th>
                    <td>
                        <input type="text" id="sunideas_history_secret" name="sunideas_history_secret"
                               value="<?php echo esc_attr(get_option('sunideas_history_secret') ?: wp_generate_password(32, false)); ?>" class="regular-text">
                        <p class="description">משמש לזהות בבטחה איזה משתמש מחובר כשהכלי (שרץ מ-GitHub) שומר/טוען את ההיסטוריה שלו - לא צריך לגעת בזה, נוצר אוטומטית.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sunideas_google_client_id">Google Client ID (התחברות עם Google)</label></th>
                    <td>
                        <input type="text" id="sunideas_google_client_id" name="sunideas_google_client_id"
                               value="<?php echo esc_attr(get_option('sunideas_google_client_id', '')); ?>" class="regular-text"
                               placeholder="123456789-abc...apps.googleusercontent.com">
                        <p class="description">מתקבל מ-Google Cloud Console (Credentials → OAuth 2.0 Client IDs). התחברות עם Google תעבוד רק עבור אימיילים שכבר יש להם חשבון קיים באתר (כלומר כבר שילמו/נרשמו) - לא יוצר חשבונות חדשים.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sunideas_monthly_price">מחיר מנוי חודשי (₪)</label></th>
                    <td><input type="number" step="0.01" id="sunideas_monthly_price" name="sunideas_monthly_price"
                               value="<?php echo esc_attr(get_option('sunideas_monthly_price', '65')); ?>" class="small-text">
                    <p class="description">מזהה תשלום חודשי → מאריך גישה ב-30 יום.</p></td>
                </tr>
                <tr>
                    <th><label for="sunideas_annual_monthly_price">מחיר מנוי "שנתי" (לחודש, ₪) - להצגה בלבד</label></th>
                    <td><input type="number" step="0.01" id="sunideas_annual_monthly_price" name="sunideas_annual_monthly_price"
                               value="<?php echo esc_attr(get_option('sunideas_annual_monthly_price', '49.99')); ?>" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_yearly_full_price">מחיר שנתי מלא, ששולם מראש (₪)</label></th>
                    <td><input type="number" step="0.01" id="sunideas_yearly_full_price" name="sunideas_yearly_full_price"
                               value="<?php echo esc_attr(get_option('sunideas_yearly_full_price', '599.88')); ?>" class="small-text">
                    <p class="description">חשוב! זה הסכום שבאמת נגבה בתשלום חד-פעמי מראש ב-Grow. מזהה תשלום כזה → מאריך גישה ב-365 יום.</p></td>
                </tr>
                <tr>
                    <th><label for="sunideas_twoyear_full_price">מחיר דו-שנתי מלא, ששולם מראש (₪)</label></th>
                    <td><input type="number" step="0.01" id="sunideas_twoyear_full_price" name="sunideas_twoyear_full_price"
                               value="<?php echo esc_attr(get_option('sunideas_twoyear_full_price', '959.76')); ?>" class="small-text">
                    <p class="description">מזהה תשלום כזה → מאריך גישה ב-730 יום.</p></td>
                </tr>
                <tr>
                    <th><label for="sunideas_require_active_subscription">רמת ההגבלה</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="sunideas_require_active_subscription" name="sunideas_require_active_subscription" value="1"
                                <?php checked(get_option('sunideas_require_active_subscription', '0'), '1'); ?>>
                            לדרוש מנוי פעיל (תשלום) בנוסף להתחברות
                        </label>
                        <p class="description">כרגע לא מסומן = כל משתמש מחובר (עם שם משתמש+סיסמה) יכול להיכנס לכלי, בלי קשר לתשלום - שלב זמני לבדיקות. סמנו כשמחברים את התשלום בפועל.</p>
                    </td>
                </tr>

                <tr><th colspan="2"><h2>עמודי שיווק ציבוריים (בית / הרשמה / התחברות)</h2>
                <p class="description">כל עמוד כאן מוצג במסך מלא, עוקף לגמרי את תבנית האתר (ללא תפריט/פוטר של וורדפרס) - בדיוק כמו עמוד הכלי.</p></th></tr>
                <tr>
                    <th><label for="sunideas_home_page_url">נתיב דף הבית</label></th>
                    <td><input type="text" id="sunideas_home_page_url" name="sunideas_home_page_url"
                               value="<?php echo esc_attr(get_option('sunideas_home_page_url', '/')); ?>" class="regular-text" placeholder="/"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_home_iframe_url">כתובת עיצוב דף הבית (GitHub Pages)</label></th>
                    <td><input type="text" id="sunideas_home_iframe_url" name="sunideas_home_iframe_url"
                               value="<?php echo esc_attr(get_option('sunideas_home_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-site.html')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_signup_page_url">נתיב עמוד הרשמה</label></th>
                    <td><input type="text" id="sunideas_signup_page_url" name="sunideas_signup_page_url"
                               value="<?php echo esc_attr(get_option('sunideas_signup_page_url', '/הרשמה/')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_signup_iframe_url">כתובת עיצוב עמוד הרשמה</label></th>
                    <td><input type="text" id="sunideas_signup_iframe_url" name="sunideas_signup_iframe_url"
                               value="<?php echo esc_attr(get_option('sunideas_signup_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-signup.html')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_login_page_url">נתיב עמוד התחברות</label></th>
                    <td><input type="text" id="sunideas_login_page_url" name="sunideas_login_page_url"
                               value="<?php echo esc_attr(get_option('sunideas_login_page_url', '/התחברות/')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_login_iframe_url">כתובת עיצוב עמוד התחברות</label></th>
                    <td><input type="text" id="sunideas_login_iframe_url" name="sunideas_login_iframe_url"
                               value="<?php echo esc_attr(get_option('sunideas_login_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-login.html')); ?>" class="regular-text"></td>
                </tr>

                <tr>
                    <th><label for="sunideas_tool_page_url">כתובת עמוד הכלי (להגבלת גישה)</label></th>
                    <td><input type="text" id="sunideas_tool_page_url" name="sunideas_tool_page_url"
                               value="<?php echo esc_attr(get_option('sunideas_tool_page_url', '/הכלי/')); ?>" class="regular-text"
                               placeholder="/הכלי/ או /app/"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_tool_iframe_url">כתובת הכלי עצמו (GitHub Pages)</label></th>
                    <td><input type="text" id="sunideas_tool_iframe_url" name="sunideas_tool_iframe_url"
                               value="<?php echo esc_attr(get_option('sunideas_tool_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/mindmap.html')); ?>" class="regular-text"></td>
                </tr>

                <tr><th colspan="2"><h2>פרטי API של קארדקום (להפקת חשבוניות)</h2></th></tr>
                <tr>
                    <th><label for="sunideas_cardcom_terminal">מספר טרמינל</label></th>
                    <td><input type="text" id="sunideas_cardcom_terminal" name="sunideas_cardcom_terminal"
                               value="<?php echo esc_attr(get_option('sunideas_cardcom_terminal')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_cardcom_username">שם משתמש API</label></th>
                    <td><input type="text" id="sunideas_cardcom_username" name="sunideas_cardcom_username"
                               value="<?php echo esc_attr(get_option('sunideas_cardcom_username')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sunideas_cardcom_api_password">סיסמת API</label></th>
                    <td><input type="password" id="sunideas_cardcom_api_password" name="sunideas_cardcom_api_password"
                               value="<?php echo esc_attr(get_option('sunideas_cardcom_api_password')); ?>" class="regular-text" autocomplete="new-password"></td>
                </tr>
            </table>
            <?php submit_button('שמירת הגדרות'); ?>
        </form>

        <h2>מנויים פעילים ותוקף</h2>
        <?php sunideas_render_subscribers(); ?>

        <h2>יומן אירועים אחרונים</h2>
        <?php sunideas_render_log(); ?>

        <?php $last_failed = get_option('sunideas_last_failed_payload'); ?>
        <?php if (!empty($last_failed)): ?>
            <h2>המטען (payload) האחרון שנכשל בזיהוי אימייל</h2>
            <p class="description">זה בדיוק מה ש-Grow (או כל מעבד אחר) שלחו בפעם האחרונה שלא הצלחנו לחלץ ממנה כתובת מייל - שימושי לאבחון מהיר.</p>
            <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($last_failed); ?></textarea>
        <?php endif; ?>

        <?php $last_cardcom = get_option('sunideas_last_cardcom_response'); ?>
        <?php if (!empty($last_cardcom)): ?>
            <h2>תגובת קארדקום האחרונה (הפקת חשבונית)</h2>
            <p class="description">התגובה המדויקת שקארדקום החזירה בפעם האחרונה שהפקת החשבונית נכשלה.</p>
            <textarea readonly rows="6" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($last_cardcom); ?></textarea>
        <?php endif; ?>
    </div>
    <?php
}

function sunideas_render_subscribers() {
    $users = get_users(['meta_key' => 'sunideas_subscription_active']);
    if (empty($users)) {
        echo '<p>עדיין אין משתמשים עם היסטוריית תשלום.</p>';
        return;
    }
    echo '<table class="widefat"><thead><tr><th>אימייל</th><th>סטטוס</th><th>תוקף עד</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $active = get_user_meta($u->ID, 'sunideas_subscription_active', true);
        $expiry = (int) get_user_meta($u->ID, 'sunideas_subscription_expiry', true);
        $expired = $expiry > 0 && $expiry < time();
        $status = ($active === '1' && !$expired) ? '✅ פעיל' : ($expired ? '⏳ פג תוקף' : '❌ לא פעיל');
        $expiry_str = $expiry > 0 ? date_i18n('d/m/Y', $expiry) : '-';
        echo '<tr><td>' . esc_html($u->user_email) . '</td><td>' . esc_html($status) . '</td><td>' . esc_html($expiry_str) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function sunideas_render_log() {
    $log = get_option('sunideas_event_log', []);
    if (empty($log)) {
        echo '<p>אין עדיין אירועים.</p>';
        return;
    }
    echo '<table class="widefat"><thead><tr><th>זמן</th><th>סוג</th><th>אימייל</th><th>סטטוס</th></tr></thead><tbody>';
    foreach (array_reverse($log) as $entry) {
        echo '<tr><td>' . esc_html($entry['time']) . '</td><td>' . esc_html($entry['type']) . '</td><td>' . esc_html($entry['email']) . '</td><td>' . esc_html($entry['status']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function sunideas_log_event($type, $email, $status) {
    $log = get_option('sunideas_event_log', []);
    $log[] = ['time' => current_time('mysql'), 'type' => $type, 'email' => $email, 'status' => $status];
    if (count($log) > 100) { $log = array_slice($log, -100); } // שומרים רק 100 אחרונים
    update_option('sunideas_event_log', $log);
}

// ============================================================
// נקודת הקצה (REST endpoint) שמקבלת את ה-Webhook מ-Grow
// ============================================================
add_action('rest_api_init', function () {
    register_rest_route('sunideas/v1', '/grow-webhook', [
        'methods' => 'POST',
        'callback' => 'sunideas_handle_grow_webhook',
        'permission_callback' => '__return_true', // האימות עצמו קורה בתוך הפונקציה, לפי המפתח הסודי
    ]);
    register_rest_route('sunideas/v1', '/save-workspace', [
        'methods' => 'POST',
        'callback' => 'sunideas_handle_save_workspace',
        'permission_callback' => '__return_true', // האימות קורה בתוך הפונקציה, לפי הטוקן החתום
    ]);
    register_rest_route('sunideas/v1', '/load-workspace', [
        'methods' => 'GET',
        'callback' => 'sunideas_handle_load_workspace',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('sunideas/v1', '/google-login', [
        'methods' => 'POST',
        'callback' => 'sunideas_handle_google_login',
        'permission_callback' => '__return_true', // האימות קורה בתוך הפונקציה מול Google עצמה
    ]);
    register_rest_route('sunideas/v1', '/google-config', [
        'methods' => 'GET',
        'callback' => function () {
            return new WP_REST_Response(['client_id' => get_option('sunideas_google_client_id', '')], 200);
        },
        'permission_callback' => '__return_true', // Client ID אינו סוד - מיועד להיות גלוי בקוד הצד-לקוח
    ]);
    register_rest_route('sunideas/v1', '/start-trial', [
        'methods' => 'POST',
        'callback' => 'sunideas_handle_start_trial',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('sunideas/v1', '/check-trial-limit', [
        'methods' => 'POST',
        'callback' => 'sunideas_handle_check_trial_limit',
        'permission_callback' => '__return_true', // האימות קורה בתוך הפונקציה, לפי הטוקן החתום
    ]);
});

// הכלי רץ מ-GitHub Pages (מקור אחר) - חייבים לאשר CORS על נקודות הקצה האלה
add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) {
        $origin = get_http_origin();
        $allowed = ['https://eyalmadar5.github.io', 'https://ideabooster.app'];
        if ($origin && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            // חייב להיות true כדי שקריאות fetch עם credentials:'include' (התחברות/הרשמה
            // חוצות-מקור מתוך ה-iframe) יוכלו בכלל לקבל את עוגיית האימות של וורדפרס.
            // בטוח לעשות זאת כי Allow-Origin תמיד מקור ספציפי שאומת, לעולם לא "*".
            header('Access-Control-Allow-Credentials: true');
        }
        return $value;
    });
}, 15);

function sunideas_handle_save_workspace(WP_REST_Request $request) {
    nocache_headers();
    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_params();

    $uid = $params['uid'] ?? '';
    $exp = $params['exp'] ?? '';
    $tok = $params['tok'] ?? '';
    if (!sunideas_verify_history_token($uid, $exp, $tok)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 401);
    }

    $data = $params['data'] ?? '';
    if (!is_string($data) || strlen($data) > 4000000) { // ~4MB תקרה בטיחותית
        return new WP_REST_Response(['error' => 'invalid or oversized data'], 400);
    }

    update_user_meta((int) $uid, 'sunideas_workspace_data', $data);
    return new WP_REST_Response(['ok' => true], 200);
}

function sunideas_handle_load_workspace(WP_REST_Request $request) {
    nocache_headers(); // קריטי - זה נתון פרטי לכל משתמש, אסור בשום מקרה שיהיה בקאש (של הדפדפן, LiteSpeed וכו')
    $uid = $request->get_param('uid');
    $exp = $request->get_param('exp');
    $tok = $request->get_param('tok');
    if (!sunideas_verify_history_token($uid, $exp, $tok)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 401);
    }

    $data = get_user_meta((int) $uid, 'sunideas_workspace_data', true);
    return new WP_REST_Response(['ok' => true, 'data' => $data ?: null], 200);
}

/**
 * התחברות עם Google - מאמתים את ה-ID token מול גוגל עצמה, מוודאים שהוא הונפק
 * עבור אפליקציית ה-Client ID שלנו (לא רק שהוא token אמיתי כלשהו), ומתחברים
 * רק אם כבר קיים משתמש בוורדפרס עם בדיוק אותו אימייל - כלומר מישהו ששילם
 * ונרשם בעבר תחת האימייל הזה. אין כאן יצירת חשבון חדש - רק כניסה לקיים.
 * הלוגיקה עצמה מופרדת מהחזרת תגובת ה-HTTP, מאותה סיבה כמו בהרשמת ניסיון
 * (admin-post עם ניווט אמיתי, לא fetch חוצה-מקור, כדי שהעוגיה תיקבע נכון).
 */
function sunideas_google_login_core($id_token) {
    if (empty($id_token)) {
        return ['ok' => false, 'error' => 'missing_token'];
    }
    $client_id = get_option('sunideas_google_client_id', '');
    if (empty($client_id)) {
        return ['ok' => false, 'error' => 'google_not_configured'];
    }

    $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
    $response = wp_remote_get($verify_url, ['timeout' => 8]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return ['ok' => false, 'error' => 'google_verification_failed'];
    }
    $token_data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($token_data['email']) || ($token_data['email_verified'] ?? 'false') !== 'true') {
        return ['ok' => false, 'error' => 'email_not_verified'];
    }
    if (($token_data['aud'] ?? '') !== $client_id) {
        sunideas_log_event('אימות גוגל נכשל', $token_data['email'] ?? '-', 'aud לא תואם - token לא הונפק עבור האפליקציה הזו');
        return ['ok' => false, 'error' => 'invalid_audience'];
    }

    $email = sanitize_email($token_data['email']);
    $user = get_user_by('email', $email);
    if (!$user) {
        sunideas_log_event('התחברות גוגל', $email, 'נדחה - אין חשבון קיים תחת האימייל הזה');
        return ['ok' => false, 'error' => 'no_account', 'email' => $email];
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    sunideas_log_event('התחברות גוגל', $email, 'הצלחה');
    $tool_url = home_url(get_option('sunideas_tool_page_url', '/הכלי/'));
    return ['ok' => true, 'redirect' => $tool_url];
}

function sunideas_handle_google_login(WP_REST_Request $request) {
    nocache_headers();
    $params = $request->get_json_params();
    $result = sunideas_google_login_core($params['credential'] ?? '');
    return new WP_REST_Response($result, $result['ok'] ? 200 : 401);
}

function sunideas_handle_google_login_formpost() {
    $result = sunideas_google_login_core($_POST['credential'] ?? '');
    if ($result['ok']) {
        wp_safe_redirect($result['redirect']);
    } else {
        $login_url = home_url(get_option('sunideas_login_page_url', '/התחברות/'));
        wp_safe_redirect(add_query_arg('google_error', $result['error'], $login_url));
    }
    exit;
}
add_action('admin_post_nopriv_sunideas_google_login', 'sunideas_handle_google_login_formpost');
add_action('admin_post_sunideas_google_login', 'sunideas_handle_google_login_formpost');

/**
 * הרשמה מינימלית לניסיון חינם - רק אימייל, סיסמה נוצרת אוטומטית ונשלחת במייל.
 * לא עובר דרך Grow/תשלום בכלל. אם כבר קיים חשבון עם האימייל הזה (בין אם ניסיון
 * ובין אם בתשלום) - לא יוצרים חשבון נוסף ולא "מאפסים" ניסיון שכבר נוצל.
 * הפונקציה הזו רק מבצעת את הלוגיקה ומחזירה מערך תוצאה - לא שולחת תגובת HTTP
 * בעצמה, כדי שאפשר יהיה לקרוא לה גם מנקודת קצה REST (JSON) וגם מנקודת קצה
 * admin-post רגילה (הפניה אמיתית ברמת הדפדפן, נחוצה כדי שעוגיית ההתחברות
 * תיקבע נכון - קריאת fetch חוצת-מקור מתוך ה-iframe לא תמיד קובעת עוגיות
 * בגלל הגבלות SameSite/צד-שלישי בדפדפנים מודרניים).
 */
function sunideas_start_trial_core($email) {
    $email = sanitize_email($email);
    if (empty($email) || !is_email($email)) {
        return ['ok' => false, 'error' => 'invalid_email'];
    }
    $thank_you_url = home_url('/תודה/');

    $existing = get_user_by('email', $email);
    if ($existing) {
        wp_set_current_user($existing->ID);
        wp_set_auth_cookie($existing->ID, true);
        return ['ok' => true, 'redirect' => $thank_you_url, 'existing' => true];
    }

    $username = sanitize_user(current(explode('@', $email)) . '_' . wp_rand(1000, 9999));
    $random_password = wp_generate_password(14, true);
    $user_id = wp_create_user($username, $random_password, $email);
    if (is_wp_error($user_id)) {
        sunideas_log_event('שגיאת יצירת ניסיון חינם', $email, $user_id->get_error_message());
        return ['ok' => false, 'error' => 'signup_failed'];
    }

    update_user_meta($user_id, 'sunideas_subscription_active', '0');
    update_user_meta($user_id, 'sunideas_is_trial', '1');
    update_user_meta($user_id, 'sunideas_trial_expansions_used', '0');
    update_user_meta($user_id, 'sunideas_trial_scripts_used', '0');

    $tool_url = home_url(get_option('sunideas_tool_page_url', '/הכלי/'));
    $login_url = wp_login_url($tool_url);
    $subject = 'הניסיון החינמי שלכם ב-Idea Booster מוכן';
    $message = "שלום,\n\n"
        . "פרטי הכניסה שלכם לניסיון החינמי:\n\n"
        . "שם משתמש: {$username}\n"
        . "סיסמה זמנית: {$random_password}\n\n"
        . "כניסה כאן: {$login_url}\n\n"
        . "בברכה,\nצוות Idea Booster";
    wp_mail($email, $subject, $message);
    sunideas_log_event('ניסיון חינם נוצר', $email, 'הצלחה');

    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    return ['ok' => true, 'redirect' => $thank_you_url, 'existing' => false];
}

function sunideas_handle_start_trial(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $result = sunideas_start_trial_core($params['email'] ?? '');
    return new WP_REST_Response($result, $result['ok'] ? 200 : 400);
}

/**
 * גרסת admin-post: מטופלת כניווט רגיל של הדפדפן (form POST עם target="_top"),
 * לא כקריאת fetch מתוך ה-iframe - כך שקביעת עוגיית ההתחברות קורית באותו
 * מקור בדיוק (ideabooster.app), בלי שום מגבלת CORS/SameSite חוצה-מקור.
 * זו אותה שיטה בדיוק שכבר עובדת היום עבור טופס ההתחברות הרגיל (wp-login.php).
 */
function sunideas_handle_start_trial_formpost() {
    $email = sanitize_email($_POST['email'] ?? '');
    $result = sunideas_start_trial_core($email);
    if ($result['ok']) {
        wp_safe_redirect($result['redirect']);
    } else {
        $signup_url = home_url(get_option('sunideas_signup_page_url', '/הרשמה/'));
        wp_safe_redirect(add_query_arg('trial_error', $result['error'], $signup_url));
    }
    exit;
}
add_action('admin_post_nopriv_sunideas_start_trial', 'sunideas_handle_start_trial_formpost');
add_action('admin_post_sunideas_start_trial', 'sunideas_handle_start_trial_formpost');

/**
 * בדיקה + ספירה של מגבלות הניסיון החינם - הלב של האכיפה. חייב לרוץ בצד השרת
 * (לא רק להסתמך על מה שהכלי "מחליט" בצד הלקוח), אחרת ניקוי עוגיות/גלישה
 * בסתר היו מאפסים את המונה. הכל נקשר לחשבון המשתמש (מזהה + אימייל) שמאומת
 * בטוקן החתום - לא למצב הדפדפן המקומי.
 *
 * חוקי הניסיון החינם:
 * - מנוי משלם פעיל (subscription_active=1, לא פג תוקף) -> תמיד מותר, בלי ספירה.
 * - הרחבת "עוד רעיונות" (action=expansion): מותר פעם אחת נוספת בסך הכל (מעבר
 *   למפה הראשונית עצמה, שלא עוברת דרך הבדיקה הזו כלל).
 * - תסריט ריל (action=script): מותר פעם אחת בסך הכל.
 * - כל פעולה אחרת (action=bio / product / landing) -> בתשלום בלבד, לעולם לא
 *   מותר במסלול ניסיון, ללא קשר למונים.
 */
function sunideas_handle_check_trial_limit(WP_REST_Request $request) {
    nocache_headers();
    $uid = $request->get_param('uid');
    $exp = $request->get_param('exp');
    $tok = $request->get_param('tok');
    $action = sanitize_text_field($request->get_param('action') ?? '');
    if (!sunideas_verify_history_token($uid, $exp, $tok)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 401);
    }

    $user_id = (int) $uid;
    $active = get_user_meta($user_id, 'sunideas_subscription_active', true);
    $expiry = (int) get_user_meta($user_id, 'sunideas_subscription_expiry', true);
    $is_paid = ($active === '1' && $expiry > time());
    if ($is_paid) {
        return new WP_REST_Response(['allowed' => true, 'paid' => true], 200);
    }

    if ($action === 'expansion') {
        $used = (int) get_user_meta($user_id, 'sunideas_trial_expansions_used', true);
        if ($used >= 1) {
            return new WP_REST_Response(['allowed' => false, 'paid' => false, 'reason' => 'trial_limit_reached'], 200);
        }
        update_user_meta($user_id, 'sunideas_trial_expansions_used', (string) ($used + 1));
        return new WP_REST_Response(['allowed' => true, 'paid' => false], 200);
    }

    if ($action === 'script') {
        $used = (int) get_user_meta($user_id, 'sunideas_trial_scripts_used', true);
        if ($used >= 1) {
            return new WP_REST_Response(['allowed' => false, 'paid' => false, 'reason' => 'trial_limit_reached'], 200);
        }
        update_user_meta($user_id, 'sunideas_trial_scripts_used', (string) ($used + 1));
        return new WP_REST_Response(['allowed' => true, 'paid' => false], 200);
    }

    // כל פעולה אחרת (ביו/מוצר/דף נחיתה) - בתשלום בלבד, לא חלק מהניסיון בכלל
    return new WP_REST_Response(['allowed' => false, 'paid' => false, 'reason' => 'paid_only'], 200);
}

function sunideas_find_email_recursive($data) {
    if (!is_array($data)) return '';
    // שמות שדות ידועים שGrow (או מעבדי תשלום דומים) עשויים להשתמש בהם
    $known_keys = ['payerEmail', 'email', 'clientEmail', 'customerEmail', 'buyerEmail', 'payer_email', 'customer_email'];
    foreach ($known_keys as $k) {
        if (!empty($data[$k]) && is_string($data[$k]) && strpos($data[$k], '@') !== false) {
            return $data[$k];
        }
    }
    // גיבוי: כל שדה שהמפתח שלו מכיל "mail" (לא תלוי רישיות) והערך נראה כמו אימייל
    foreach ($data as $key => $value) {
        if (is_string($value) && strpos($value, '@') !== false && stripos((string)$key, 'mail') !== false) {
            return $value;
        }
        if (is_array($value)) {
            $found = sunideas_find_email_recursive($value);
            if (!empty($found)) return $found;
        }
    }
    return '';
}

function sunideas_handle_grow_webhook(WP_REST_Request $request) {
    // אימות: המפתח הסודי חייב להגיע כפרמטר בכתובת (?key=...)
    $expected_key = get_option('sunideas_webhook_secret');
    $provided_key = $request->get_param('key');
    if (empty($expected_key) || $provided_key !== $expected_key) {
        sunideas_log_event('אימות נכשל', '-', 'נדחה - מפתח שגוי');
        return new WP_REST_Response(['error' => 'unauthorized'], 401);
    }

    $body = $request->get_json_params();
    if (empty($body)) {
        return new WP_REST_Response(['error' => 'empty body'], 400);
    }

    // Grow שולחת כמה פורמטים אפשריים - מנסים לשלוף שדות בכל הפורמטים הידועים
    $data = isset($body['data']) ? $body['data'] : $body; // פורמט PaymentLinks עוטף בתוך "data"

    $email = sanitize_email(sunideas_find_email_recursive($data));
    $full_name = sanitize_text_field($data['fullName'] ?? $data['payer_name'] ?? $data['customerName'] ?? '');
    $payment_sum = $data['sum'] ?? $data['paymentSum'] ?? 0;
    $description = sanitize_text_field($data['description'] ?? $data['paymentDesc'] ?? '');
    $is_failure = isset($body['error_message']) || isset($body['charges_attempts']);
    $status_code = $data['statusCode'] ?? null;

    if (empty($email) || !is_email($email)) {
        // שומרים את המבנה המדויק שהתקבל, כדי לאבחן מיד בפעם הבאה בלי ניחושים
        update_option('sunideas_last_failed_payload', wp_json_encode($body));
        sunideas_log_event('שגיאה', '-', 'לא נמצא אימייל תקין בבקשה - ראו "המטען האחרון שנכשל" למטה');
        return new WP_REST_Response(['error' => 'no valid email in payload'], 400);
    }

    if ($is_failure) {
        // חיוב חוזר נכשל - חוסמים גישה
        sunideas_deactivate_subscriber($email);
        sunideas_log_event('חיוב נכשל', $email, 'גישה נחסמה');
        return new WP_REST_Response(['ok' => true, 'action' => 'deactivated'], 200);
    }

    // תשלום מוצלח - מפעילים/מחדשים גישה
    $is_new_user = sunideas_activate_subscriber($email, $full_name, $payment_sum);

    // הפקת חשבונית דרך קארדקום (לא עוצר את התהליך אם נכשל - רק מתעד)
    $invoice_result = sunideas_create_cardcom_invoice($email, $full_name, $payment_sum, $description);

    sunideas_log_event(
        $is_new_user ? 'הרשמה חדשה' : 'חידוש מנוי',
        $email,
        $invoice_result ? 'הופעל + חשבונית נשלחה' : 'הופעל (חשבונית נכשלה - בדקו יומן)'
    );

    return new WP_REST_Response(['ok' => true, 'action' => 'activated', 'new_user' => $is_new_user], 200);
}

// ============================================================
// ניהול משתמשים - הפעלה/חסימה של גישה
// ============================================================
function sunideas_plan_days_for_sum($sum) {
    $sum = round(floatval($sum), 2);
    $monthly = round(floatval(get_option('sunideas_monthly_price', 65)), 2);
    $yearly  = round(floatval(get_option('sunideas_yearly_full_price', 599.88)), 2);
    $twoyear = round(floatval(get_option('sunideas_twoyear_full_price', 959.76)), 2);
    $tolerance = 1.0; // סבלנות קטנה לעיגולי מטבע

    if (abs($sum - $twoyear) < $tolerance) return 730;
    if (abs($sum - $yearly)  < $tolerance) return 365;
    if (abs($sum - $monthly) < $tolerance) return 30;
    return 30; // ברירת מחדל בטוחה - עדיף גישה קצרה שאפשר להאריך ידנית, מאשר לחסום בטעות
}

function sunideas_activate_subscriber($email, $full_name, $sum = 0) {
    $user = get_user_by('email', $email);
    $is_new = false;

    if (!$user) {
        // יוצרים משתמש חדש עם סיסמה אקראית, ושולחים לו מייל עם פרטי כניסה
        $username = sanitize_user(current(explode('@', $email)) . '_' . wp_rand(100, 999));
        $random_password = wp_generate_password(14, true);
        $user_id = wp_create_user($username, $random_password, $email);

        if (is_wp_error($user_id)) {
            sunideas_log_event('שגיאת יצירת משתמש', $email, $user_id->get_error_message());
            return false;
        }

        if (!empty($full_name)) {
            wp_update_user(['ID' => $user_id, 'display_name' => $full_name]);
        }

        $user = get_user_by('id', $user_id);

        // שולחים מייל עם פרטי הכניסה
        $tool_url = home_url(get_option('sunideas_tool_page_url', '/'));
        $login_url = wp_login_url($tool_url);
        $subject = 'ברוכים הבאים לשמש הרעיונות - פרטי הכניסה שלכם';
        $message = "שלום {$full_name},\n\n"
            . "ההרשמה שלכם הושלמה בהצלחה!\n\n"
            . "שם משתמש: {$username}\n"
            . "סיסמה זמנית: {$random_password}\n\n"
            . "כניסה כאן: {$login_url}\n\n"
            . "מומלץ להחליף את הסיסמה לאחר הכניסה הראשונה (בעמוד הפרופיל שלכם).\n\n"
            . "בברכה,\nצוות שמש הרעיונות";
        wp_mail($email, $subject, $message);

        $is_new = true;
    }

    // קובעים/מאריכים את תוקף המנוי לפי הסכום ששולם - מתחילים לספור מהמאוחר
    // מבין "עכשיו" לבין תוקף קיים, כך שחידוש מוקדם לא "מפסיד" זמן שכבר שולם עליו.
    $days = sunideas_plan_days_for_sum($sum);
    $current_expiry = (int) get_user_meta($user->ID, 'sunideas_subscription_expiry', true);
    $base = max(time(), $current_expiry);
    $new_expiry = $base + ($days * DAY_IN_SECONDS);

    // מסמנים כמנוי פעיל + תוקף + חותם הזמן של החידוש האחרון
    update_user_meta($user->ID, 'sunideas_subscription_active', '1');
    update_user_meta($user->ID, 'sunideas_subscription_expiry', $new_expiry);
    update_user_meta($user->ID, 'sunideas_last_payment', current_time('mysql'));


    return $is_new;
}

function sunideas_deactivate_subscriber($email) {
    $user = get_user_by('email', $email);
    if ($user) {
        update_user_meta($user->ID, 'sunideas_subscription_active', '0');
    }
}

// ============================================================
// הגבלת גישה + הצגת הכלי - עוקף לגמרי את התבנית (מסך מלא, גם במובייל)
// ============================================================
// ============================================================
// טוקן זיהוי חתום למשתמש - מאפשר לכלי (שרץ מדומיין אחר, GitHub)
// לדעת "מי מחובר" בלי גישה ישירה לעוגיות/session של וורדפרס
// ============================================================
function sunideas_history_secret() {
    $secret = get_option('sunideas_history_secret');
    if (empty($secret)) {
        $secret = wp_generate_password(32, false);
        update_option('sunideas_history_secret', $secret);
    }
    return $secret;
}
function sunideas_make_history_token($user_id) {
    $exp = time() + (7 * DAY_IN_SECONDS); // תוקף שבוע, מתחדש בכל טעינה של עמוד הכלי
    $sig = hash_hmac('sha256', $user_id . '|' . $exp, sunideas_history_secret());
    return ['uid' => $user_id, 'exp' => $exp, 'tok' => $sig];
}
function sunideas_verify_history_token($user_id, $exp, $token) {
    $user_id = (int) $user_id;
    $exp = (int) $exp;
    if ($user_id <= 0 || $exp < time()) return false;
    $expected = hash_hmac('sha256', $user_id . '|' . $exp, sunideas_history_secret());
    return hash_equals($expected, (string) $token);
}

function sunideas_render_fullbleed_page($iframe_url, $is_tool_page = false, $pixel_event = null){
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if(!empty($qs)){
        $iframe_url .= (strpos($iframe_url, '?') === false ? '?' : '&') . $qs;
    }
    if ($is_tool_page && !$pixel_event) $pixel_event = 'PageView';
    ?><!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Idea Booster - שמש הרעיונות</title>
<script type="text/javascript">
    (function(c,l,a,r,i,t,y){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
        t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
    })(window, document, "clarity", "script", "xkth00lm81");
</script>
<?php if ($pixel_event): ?>
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '1026770009939982');
fbq('track', '<?php echo esc_js($pixel_event); ?>');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=1026770009939982&ev=<?php echo esc_attr($pixel_event); ?>&noscript=1"
/></noscript>
<?php endif; ?>
<?php if ($is_tool_page): ?>
<link rel="manifest" href="https://eyalmadar5.github.io/sun-of-ideas-tool/manifest.json">
<link rel="apple-touch-icon" href="https://eyalmadar5.github.io/sun-of-ideas-tool/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Idea Booster">
<meta name="theme-color" content="#C9622A">
<?php endif; ?>
<style>
  html, body { margin:0; padding:0; width:100%; height:100%; overflow:hidden; background:#F6F1E4; }
  iframe { position:fixed; inset:0; width:100vw; height:100dvh; border:none; display:block; }
  <?php if ($is_tool_page): ?>
  #sunideas-install-banner{
    position:fixed; right:16px; left:16px; bottom:16px; z-index:99999;
    background:#fff; border:1.5px solid #E4DBC4; border-radius:16px;
    box-shadow:0 12px 32px rgba(36,38,31,0.22);
    padding:16px; display:none; align-items:center; gap:12px;
    font-family:-apple-system,'Heebo',sans-serif; direction:rtl;
  }
  #sunideas-install-banner.open{ display:flex; }
  #sunideas-install-banner img{ width:48px; height:48px; border-radius:12px; flex:0 0 auto; }
  #sunideas-install-text{ flex:1 1 auto; min-width:0; }
  #sunideas-install-text b{ display:block; font-size:14.5px; color:#3A2A1A; margin-bottom:2px; }
  #sunideas-install-text span{ display:block; font-size:12.5px; color:#8A7A66; }
  #sunideas-install-actions{ display:flex; flex-direction:column; gap:6px; flex:0 0 auto; }
  #sunideas-install-btn{
    background:#C9622A; color:#fff; border:none; border-radius:10px;
    padding:9px 14px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap;
  }
  #sunideas-install-dismiss{
    background:none; border:none; color:#8A7A66; font-size:11.5px; cursor:pointer; text-decoration:underline;
  }
  #sunideas-install-checkbox-row{
    display:flex; align-items:center; gap:5px; font-size:11px; color:#8A7A66; margin-top:6px;
  }
  <?php endif; ?>
</style>
</head>
<body>
  <iframe src="<?php echo esc_url($iframe_url); ?>" allow="clipboard-write; camera; microphone" allowfullscreen></iframe>
  <?php if ($is_tool_page): $banner_lang = (isset($_COOKIE['sunideas_lang']) && $_COOKIE['sunideas_lang'] === 'en') ? 'en' : 'he'; ?>
  <div id="sunideas-install-banner" dir="<?php echo $banner_lang === 'en' ? 'ltr' : 'rtl'; ?>">
    <img src="https://eyalmadar5.github.io/sun-of-ideas-tool/icon-192.png" alt="Idea Booster">
    <div id="sunideas-install-text">
      <b id="sunideas-install-title"><?php echo $banner_lang === 'en' ? 'Install the tool on your home screen' : 'התקינו את הכלי על מסך הבית'; ?></b>
      <span id="sunideas-install-sub"><?php echo $banner_lang === 'en' ? 'Quick access, like an app - no address bar' : 'גישה מהירה, כמו אפליקציה - בלי שורת כתובת'; ?></span>
      <label id="sunideas-install-checkbox-row">
        <input type="checkbox" id="sunideas-install-never">
        <span><?php echo $banner_lang === 'en' ? "Don't offer this again" : 'לא להציע לי שוב'; ?></span>
      </label>
    </div>
    <div id="sunideas-install-actions">
      <button type="button" id="sunideas-install-btn"><?php echo $banner_lang === 'en' ? 'Install' : 'התקנה'; ?></button>
      <button type="button" id="sunideas-install-dismiss"><?php echo $banner_lang === 'en' ? 'Maybe later' : 'אולי מאוחר יותר'; ?></button>
    </div>
  </div>
  <script>
  (function(){
    var LS_KEY = 'sunideas_install_dismissed';
    var BANNER_LANG = '<?php echo $banner_lang; ?>';
    var isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var alreadyDismissed = false;
    try{ alreadyDismissed = localStorage.getItem(LS_KEY) === '1'; }catch(e){}
    if(!isMobile || isStandalone || alreadyDismissed) return;

    var isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    var banner = document.getElementById('sunideas-install-banner');
    var neverBox = document.getElementById('sunideas-install-never');
    var installBtn = document.getElementById('sunideas-install-btn');
    var dismissBtn = document.getElementById('sunideas-install-dismiss');

    function maybeRemember(){
      if(neverBox.checked){
        try{ localStorage.setItem(LS_KEY, '1'); }catch(e){}
      }
    }
    dismissBtn.addEventListener('click', function(){
      maybeRemember();
      banner.classList.remove('open');
    });

    var deferredPrompt = null;
    if(isIOS){
      // אין דרך תכנותית להפעיל את זה ב-iOS - מציגים הוראה במקום כפתור פעיל
      document.getElementById('sunideas-install-sub').textContent = BANNER_LANG === 'en'
        ? 'Tap the Share button \u2b06\ufe0f then "Add to Home Screen"'
        : 'הקישו על כפתור השיתוף ⬆️ ואז "הוספה למסך הבית"';
      installBtn.textContent = BANNER_LANG === 'en' ? 'Got it' : 'הבנתי';
      installBtn.addEventListener('click', function(){
        maybeRemember();
        banner.classList.remove('open');
      });
      setTimeout(function(){ banner.classList.add('open'); }, 1500);
    } else {
      window.addEventListener('beforeinstallprompt', function(e){
        e.preventDefault();
        deferredPrompt = e;
        setTimeout(function(){ banner.classList.add('open'); }, 1500);
      });
      installBtn.addEventListener('click', function(){
        if(!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(function(){
          banner.classList.remove('open');
        });
      });
      window.addEventListener('appinstalled', function(){
        try{ localStorage.setItem(LS_KEY, '1'); }catch(e){} // הותקן בפועל - לא נציע שוב לעולם
        banner.classList.remove('open');
      });
    }
  })();
  </script>
  <?php endif; ?>
</body>
</html>
<?php
    exit;
}

add_action('template_redirect', function () {
    $current_path = rtrim(urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)), '/');
    $is_front = is_front_page() || is_home() || $current_path === '';

    // --- עמודים ציבוריים (בית / הרשמה / התחברות / en): במסך מלא, בלי הגבלת גישה ---
    // חשוב: לכל אחד יש כאן ברירת מחדל אמיתית וקבועה בקוד (לא רק בתצוגת ההגדרות) -
    // כך שהאתר ממשיך לעבוד נכון גם מיד אחרי התקנה טרייה של התוסף, לפני שמישהו
    // בכלל פתח את מסך ההגדרות ולחץ שמירה.
    $public_pages = [
        ['path' => get_option('sunideas_home_page_url', '/'),
         'url'  => get_option('sunideas_home_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-site.html'),
         'lang' => 'he', 'pixel_event' => 'PageView'],
        ['path' => '/en/',
         'url'  => 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-site-en.html',
         'lang' => 'en', 'pixel_event' => 'PageView'],
        ['path' => get_option('sunideas_signup_page_url', '/הרשמה/'),
         'url'  => get_option('sunideas_signup_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-signup.html'),
         'lang' => null, 'pixel_event' => null],
        ['path' => get_option('sunideas_login_page_url', '/התחברות/'),
         'url'  => get_option('sunideas_login_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-login.html'),
         'lang' => null, 'pixel_event' => null],
        ['path' => '/תודה/',
         'url'  => 'https://eyalmadar5.github.io/sun-of-ideas-tool/idea-booster-thank-you.html',
         'lang' => null, 'pixel_event' => 'Purchase'],
    ];
    foreach ($public_pages as $p) {
        if (empty($p['url'])) continue;
        $p_path_norm = rtrim(urldecode($p['path']), '/');
        $matches_home = ($p_path_norm === '' && $is_front);
        $matches_path = ($p_path_norm !== '' && $current_path === $p_path_norm);
        if ($matches_home || $matches_path) {
            // שומרים איזו שפה זיהינו כאן ב-cookie (על כל דומיין האתר), כדי שכשהמשתמש
            // הזה יגיע בהמשך לעמוד הכלי (אחרי הרשמה/התחברות), הכלי ידע להיפתח
            // ישר באותה שפה שממנה הוא הגיע - בלי לגעת בכלל בתהליך התשלום/Webhook.
            if ($p['lang'] && !headers_sent()) {
                setcookie('sunideas_lang', $p['lang'], time() + 60 * 60 * 24 * 90, '/');
            }
            sunideas_render_fullbleed_page($p['url'], false, $p['pixel_event'] ?? null);
        }
    }

    // --- עמוד הכלי: דורש התחברות (ואולי מנוי פעיל) ---
    $tool_path = get_option('sunideas_tool_page_url', '/הכלי/');
    if (empty($tool_path)) return;

    $tool_path_norm = rtrim(urldecode($tool_path), '/');
    if ($current_path === '' || $current_path !== $tool_path_norm) return; // לא בעמוד הכלי - לא נוגעים

    if (!is_user_logged_in()) {
        auth_redirect(); // מפנה להתחברות
        exit;
    }

    $require_subscription = get_option('sunideas_require_active_subscription', '0');
    if ($require_subscription === '1') {
        $uid = get_current_user_id();
        $active = get_user_meta($uid, 'sunideas_subscription_active', true);
        $expiry = (int) get_user_meta($uid, 'sunideas_subscription_expiry', true);
        $expired = $expiry > 0 && $expiry < time();
        $is_trial_user = get_user_meta($uid, 'sunideas_is_trial', true) === '1';
        if (($active !== '1' || $expired) && !$is_trial_user) {
            if ($expired && $active === '1') {
                update_user_meta($uid, 'sunideas_subscription_active', '0'); // מנקים בו-במקום, לא מחכים ליום הבא
            }
            wp_die(
                'הגישה לכלי זמינה למנויים פעילים בלבד. אם שילמתם וזה עדיין חסום, פנו אלינו לבדיקה.',
                'גישה מוגבלת',
                ['response' => 403]
            );
        }
    }

    $tool_iframe_url = get_option('sunideas_tool_iframe_url', 'https://eyalmadar5.github.io/sun-of-ideas-tool/mindmap.html');
    $token = sunideas_make_history_token(get_current_user_id());
    $current_user = wp_get_current_user();
    $sub_active = get_user_meta($current_user->ID, 'sunideas_subscription_active', true);
    $sub_expiry = (int) get_user_meta($current_user->ID, 'sunideas_subscription_expiry', true);
    $is_trial = get_user_meta($current_user->ID, 'sunideas_is_trial', true) === '1';
    $trial_expansions_used = (int) get_user_meta($current_user->ID, 'sunideas_trial_expansions_used', true);
    $trial_scripts_used = (int) get_user_meta($current_user->ID, 'sunideas_trial_scripts_used', true);
    $detected_lang = (isset($_COOKIE['sunideas_lang']) && $_COOKIE['sunideas_lang'] === 'en') ? 'en' : 'he';
    $sep = (strpos($tool_iframe_url, '?') === false) ? '?' : '&';
    $tool_iframe_url .= $sep . 'uid=' . $token['uid'] . '&exp=' . $token['exp'] . '&tok=' . urlencode($token['tok'])
        . '&uname=' . urlencode($current_user->display_name)
        . '&uemail=' . urlencode($current_user->user_email)
        . '&subactive=' . ($sub_active === '1' ? '1' : '0')
        . '&subexpiry=' . $sub_expiry
        . '&istrial=' . ($is_trial ? '1' : '0')
        . '&trialexp=' . $trial_expansions_used
        . '&trialscr=' . $trial_scripts_used
        . '&lang=' . $detected_lang
        . '&logouturl=' . urlencode(add_query_arg('sunideas_logout', '1', home_url()));
    sunideas_render_fullbleed_page($tool_iframe_url, true);
});

// ============================================================
// הפקת חשבונית דרך קארדקום (CreateInvoice API)
// ============================================================
function sunideas_create_cardcom_invoice($email, $full_name, $sum, $description) {
    $terminal = get_option('sunideas_cardcom_terminal');
    $username = get_option('sunideas_cardcom_username');

    if (empty($terminal) || empty($username)) {
        return false; // לא הוגדרו פרטי קארדקום - מדלגים על החשבונית (התשלום/הגישה עדיין מטופלים)
    }

    // מתעדים תשלום שהתקבל דרך מעבד חיצוני (Grow) ולא דרך קארדקום עצמה,
    // באמצעות פרמטרי CustomPay - זה מה שדוקומנטציית קארדקום קוראת לו
    // "יצירת מסמך ללא חיוב אשראי" (למשל עבור מזומן/העברה/מעבד אחר).
    $response = wp_remote_post('https://secure.cardcom.solutions/Interface/CreateInvoice.aspx', [
        'timeout' => 15,
        'body' => [
            'terminalnumber' => $terminal,
            'username'       => $username,
            'InvoiceType'    => '1', // חשבונית מס קבלה - אושר מול תמיכת קארדקום
            'InvoiceHead.CustName'   => $full_name ?: $email,
            'InvoiceHead.SendByEmail' => 'true',
            'InvoiceHead.Language'    => 'he',
            'InvoiceHead.Email'       => $email,
            'InvoiceHead.IsAutoCreateUpdateAccount' => 'true',
            'InvoiceLines1.Description' => $description ?: 'מנוי Idea Booster',
            'InvoiceLines1.Price'       => $sum,
            'InvoiceLines1.Quantity'    => 1,
            'CustomPay.TransactionID' => time(),
            'CustomPay.TranDate'      => date('d/m/Y'),
            'CustomPay.Description'   => 'תשלום דרך Grow',
            'CustomPay.Asmacta'       => (string) time(),
            'CustomPay.Sum'           => $sum,
        ],
    ]);

    if (is_wp_error($response)) {
        sunideas_log_event('שגיאת חשבונית', $email, $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    parse_str($body, $parsed);
    $success = isset($parsed['ResponseCode']) && $parsed['ResponseCode'] === '0';

    if (!$success) {
        // שומרים את התגובה המדויקת מקארדקום כדי לאבחן מיד בפעם הבאה
        update_option('sunideas_last_cardcom_response', $body);
        sunideas_log_event('שגיאת חשבונית', $email, 'קארדקום דחה - ראו "תגובת קארדקום האחרונה" למטה');
    }

    return $success;
}
