<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/authlib.php');

class auth_plugin_email extends auth_plugin_base {

    public function __construct() {
        $this->authtype = 'email';
        $this->config = get_config('auth_email');
    }

    public function auth_plugin_email() {
        debugging('Deprecated constructor', DEBUG_DEVELOPER);
        self::__construct();
    }

    function user_login($username, $password) {
        global $CFG, $DB;

        if ($user = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id
        ])) {
            return validate_internal_user_password($user, $password);
        }

        return false;
    }

    function user_update_password($user, $newpassword) {
        $user = get_complete_user_data('id', $user->id);
        return update_internal_user_password($user, $newpassword);
    }

    function can_signup() {
        return true;
    }

    function user_signup($user, $notify = true) {
        return $this->user_signup_with_confirmation($user, $notify);
    }

    public function user_signup_with_confirmation($user, $notify = true, $confirmationurl = null) {

        global $CFG, $DB, $SESSION;

        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/lib.php');

        $plainpassword = $user->password;
        $user->password = hash_internal_user_password($user->password);

        if (empty($user->calendartype)) {
            $user->calendartype = $CFG->calendartype;
        }

        /*
        CORREÇÃO PRINCIPAL
        garante email válido
        */

        if (empty($user->email)) {
            $user->email = 'noreply@localhost';
        }

        $user->confirmed = 1;

        $user->id = user_create_user($user, false, false);

        user_add_password_history($user->id, $plainpassword);

        profile_save_data($user);

        if (!empty($SESSION->wantsurl)) {
            set_user_preference('auth_email_wantsurl', $SESSION->wantsurl, $user);
        }

        \core\event\user_created::create_from_userid($user->id)->trigger();

        /*
        TENTAR ENVIAR EMAIL
        MAS NÃO BLOQUEAR CADASTRO
        */

        send_confirmation_email($user, $confirmationurl);

        if ($notify) {

            global $PAGE, $OUTPUT;

            $PAGE->set_url('/login/index.php');
            $PAGE->set_title('Cadastro concluído');
            $PAGE->set_heading('Cadastro concluído');

            echo $OUTPUT->header();

            echo '<div style="padding:20px;background:#e8f5e9;border-radius:8px">';
            echo '<h3>Conta criada com sucesso</h3>';
            echo '<p>Agora você pode entrar no sistema.</p>';
            echo '</div>';

            echo $OUTPUT->footer();

        } else {
            return true;
        }
    }

    function can_confirm() {
        return false;
    }

    function user_confirm($username, $confirmsecret) {
        return AUTH_CONFIRM_OK;
    }
}
