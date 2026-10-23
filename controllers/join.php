<?php

require_once __DIR__ . '/../lib/ExclusiveBBBController.php';

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Passwords;
use ExclusiveBBB\Schedule;
use ExclusiveBBB\Services\JoinDeniedException;
use ExclusiveBBB\Services\JoinService;
use ExclusiveBBB\Settings;

/**
 * Public join page of a conference, reachable without Stud.IP login.
 */
class JoinController extends ExclusiveBBBController
{
    public function before_filter(&$action, &$args)
    {
        parent::before_filter($action, $args);

        $this->set_layout($this->get_template_factory()->open('layouts/public'));
        $this->logo = Settings::logo() ? $this->url_for('join/logo') : null;
        $this->stylesheet = $this->plugin->getPluginURL() . '/assets/join.css';
    }

    public function index_action($token = null)
    {
        if (!$this->loadConference($token)) {
            return;
        }
        $this->error = null;
    }

    public function enter_action($token = null)
    {
        $this->requirePost();
        if (!$this->loadConference($token)) {
            return;
        }

        $user = User::findCurrent();
        $name = Request::get('fullname', '');
        if (trim($name) === '' && $user && $user->id !== 'nobody') {
            $name = $user->getFullName();
        }

        try {
            $url = JoinService::join(
                $this->conference,
                $name,
                Request::get('password'),
                $user,
                $_SERVER['REMOTE_ADDR'] ?? '',
                time()
            );
            $this->redirect($url);
        } catch (JoinDeniedException $e) {
            $this->error = $this->denyMessage($e->reason);
            $this->fullname = $name;
            $this->render_action('index');
        }
    }

    public function image_action($token = null)
    {
        $conference = Conference::findByToken((string) $token);
        $this->renderImage($conference && $conference->isActive() ? $conference->image : null);
    }

    public function logo_action()
    {
        $this->renderImage(Settings::logo());
    }

    /**
     * @return bool false if the token is unknown; a 404 page has been rendered then
     */
    private function loadConference(?string $token): bool
    {
        $this->conference = Conference::findByToken((string) $token);
        if (!$this->conference) {
            PageLayout::setTitle($this->_('Konferenz nicht gefunden'));
            $this->set_status(404);
            $this->render_template('join/not_found', $this->layout);
            return false;
        }

        $now = time();
        $occurrences = $this->conference->getOccurrences();
        $this->now = $now;
        $this->state = $this->conference->isActive() ? Schedule::state($occurrences, $now) : 'cancelled';
        $this->current = Schedule::current($occurrences, $now);
        $this->next = Schedule::next($occurrences, $now);
        if ($this->state === Schedule::STATE_UPCOMING && $this->next['open_from'] - $now < 3600) {
            $this->auto_refresh = 60;
        }

        $user = User::findCurrent();
        $this->implicit_role = JoinService::implicitRole($this->conference, $user);
        $this->logged_in = $user && $user->id !== 'nobody';
        $this->fullname = $this->logged_in ? $user->getFullName() : '';

        PageLayout::setTitle($this->conference->getDisplayName());
        return true;
    }

    private function denyMessage(string $reason): string
    {
        switch ($reason) {
            case JoinService::DENY_CANCELLED:
                return $this->_('Diese Konferenz wurde storniert.');
            case JoinService::DENY_CLOSED:
                return $this->_('Diese Konferenz ist bereits beendet.');
            case JoinService::DENY_NOT_YET:
                return $this->_('Die Konferenz hat noch nicht begonnen. Teilnehmende können ab Beginn eintreten, Moderierende etwas früher.');
            case JoinService::DENY_PASSWORD_REQUIRED:
                return $this->_('Bitte geben Sie das Passwort ein.');
            case JoinService::DENY_WRONG_PASSWORD:
                return $this->_('Das Passwort ist falsch.');
            case JoinService::DENY_RATE_LIMITED:
                return $this->_('Zu viele Fehlversuche. Bitte versuchen Sie es in einigen Minuten erneut.');
            case JoinService::DENY_NAME_REQUIRED:
                return $this->_('Bitte geben Sie Ihren Namen ein.');
            default:
                return $this->_('Der Konferenzserver ist derzeit nicht erreichbar. Bitte versuchen Sie es gleich erneut.');
        }
    }
}
