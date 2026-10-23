<?php

require_once __DIR__ . '/../lib/ExclusiveBBBController.php';

use ExclusiveBBB\Log;
use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Passwords;
use ExclusiveBBB\Services\ConferenceService;
use ExclusiveBBB\Services\Mailer;

/**
 * Conferences the current user may edit: requester, booking person,
 * resource tutors/admins, course lecturers and root.
 */
class ConferencesController extends ExclusiveBBBController
{
    public function before_filter(&$action, &$args)
    {
        parent::before_filter($action, $args);
        $this->user = $this->requireLogin();
        PageLayout::setTitle($this->_('Exklusive Konferenzen'));
        if (Navigation::hasItem('/contents/exclusivebbb')) {
            Navigation::activateItem('/contents/exclusivebbb');
        }
    }

    public function index_action()
    {
        $this->conferences = ConferenceService::findEditableFor($this->user);
    }

    public function edit_action($id = null)
    {
        $this->conference = $this->loadEditable($id);
        $this->join_url = Mailer::joinUrl($this->conference);
        $this->occurrences = $this->conference->getOccurrences();
        $this->new_passwords = $_SESSION['exbbb_new_passwords'][$this->conference->id] ?? null;
        unset($_SESSION['exbbb_new_passwords'][$this->conference->id]);
    }

    public function store_action($id = null)
    {
        $this->requirePost();
        $conference = $this->loadEditable($id);

        $conference->name = mb_substr(trim(Request::get('name', '')), 0, 255);
        $conference->description = trim(Request::get('description', ''));

        try {
            if (Request::bool('delete_image')) {
                $this->deleteImage('conference-' . $conference->id);
                $conference->image = null;
            }
            $image = $this->storeUploadedImage('image', 'conference-' . $conference->id);
            if ($image) {
                $conference->image = $image;
            }
        } catch (InvalidArgumentException $e) {
            PageLayout::postError($e->getMessage());
        }

        $conference->store();
        Log::event(Log::UPDATE, $conference->resource_id, ConferenceService::describe($conference));
        PageLayout::postSuccess($this->_('Die Konferenz wurde gespeichert.'));
        $this->redirect('conferences/edit/' . $conference->id);
    }

    public function passwords_action($id = null)
    {
        $this->requirePost();
        $conference = $this->loadEditable($id);

        if (Request::submitted('generate')) {
            $attendee = Passwords::generate();
            $moderator = Passwords::generate();
        } else {
            $attendee = Request::get('attendee_password', '');
            $moderator = Request::get('moderator_password', '');
            $error = Passwords::validatePair($attendee, $moderator);
            if ($error) {
                PageLayout::postError($this->_($error));
                $this->redirect('conferences/edit/' . $conference->id);
                return;
            }
        }

        $conference->attendee_pw_hash = Passwords::hash($attendee);
        $conference->moderator_pw_hash = Passwords::hash($moderator);
        $conference->store();
        Log::event(Log::UPDATE, $conference->resource_id, 'Passwörter geändert: ' . ConferenceService::describe($conference));

        if (Request::bool('send_mail') && $conference->booking) {
            Mailer::sendPasswords($conference, ConferenceService::recipients($conference->booking), $attendee, $moderator);
        }

        // shown once on the next page, never stored in plain text
        $_SESSION['exbbb_new_passwords'][$conference->id] = ['attendee' => $attendee, 'moderator' => $moderator];
        PageLayout::postSuccess($this->_('Die Passwörter wurden geändert.'));
        $this->redirect('conferences/edit/' . $conference->id);
    }

    private function loadEditable(?string $id): Conference
    {
        $conference = Conference::find((string) $id);
        if (!$conference || !$conference->isActive() || !ConferenceService::canEdit($conference, $this->user)) {
            throw new AccessDeniedException();
        }
        return $conference;
    }
}
