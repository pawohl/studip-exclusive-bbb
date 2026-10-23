<?php
/**
 * Integration test against the dev stack: real Stud.IP booking model,
 * plugin observers, BBB mocks and Mailpit. Run inside the web container:
 *
 *   php public/plugins_packages/exclusivebbb/ExclusiveBBB/tests/integration/booking_flow.php
 *
 * With --keep-now a booking covering the current time stays on node 1 and
 * its join token and passwords are written to /tmp/exbbb-now.json (for E2E tests).
 * Synthetic data only; all other bookings it creates are removed again.
 */

require '/var/www/studip/cli/studip_cli_env.inc.php';

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Models\Node;
use ExclusiveBBB\Passwords;
use ExclusiveBBB\Services\ConferenceService;
use ExclusiveBBB\Services\Enforcer;
use ExclusiveBBB\Services\JoinDeniedException;
use ExclusiveBBB\Services\JoinService;
use ExclusiveBBB\Settings;

const MAILPIT = 'http://mailpit:8025/api/v1';
const NODE1_MOCK = 'http://bbb-node1:8090';

$keep_now = in_array('--keep-now', $argv, true);
$failures = 0;
$cleanup = [];

// remove test bookings even if a step fails with an exception
register_shutdown_function(function () use (&$cleanup) {
    foreach ($cleanup as $item) {
        if ($item && ResourceBooking::exists($item->id)) {
            ResourceBooking::find($item->id)->delete();
        }
    }
    DBManager::get()->execute("DELETE FROM exbbb_conferences WHERE status = 'cancelled'");
});

function check(bool $condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function http(string $url, string $method = 'GET'): string
{
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true]]);
    return (string) file_get_contents($url, false, $context);
}

function mailsTo(string $email): array
{
    $result = json_decode(http(MAILPIT . '/search?query=' . urlencode('to:' . $email)), true);
    return $result['messages'] ?? [];
}

function mailBody(string $id): string
{
    return json_decode(http(MAILPIT . '/message/' . $id), true)['Text'] ?? '';
}

// act as the Raumvergabe user who administers the node rooms
$admin = User::findByUsername('test_admin');
$GLOBALS['user'] = new Seminar_User($admin);
$GLOBALS['perm'] = new Seminar_Perm();

// loading the plugin registers its booking observers
check(PluginEngine::getPlugin('ExclusiveBBB') instanceof ExclusiveBBB, 'plugin loads for test_admin');

$nodes = Node::findActive();
check(count($nodes) === 2, 'two active nodes seeded');
$room = Resource::find($nodes[0]->resource_id)->getDerivedClassInstance();

// remove the "now" booking kept by a previous --keep-now run
foreach (ResourceBooking::findBySQL("resource_id = ? AND description = 'Senatssitzung (Test)'", [$room->id]) as $old) {
    $old->delete();
}
$dozent = User::findByUsername('test_dozent');

http(MAILPIT . '/messages', 'DELETE');
http(NODE1_MOCK . '/mock/reset');

// --- 1. simple booking for test_dozent, two days ahead --------------------
$day = strtotime('+2 days 10:00');
$booking = $room->createBooking($admin, $dozent->id, [['begin' => $day, 'end' => $day + 7200]]);
$cleanup[] = $booking;

check((int) $booking->preparation_time === Settings::prepSeconds(), 'preparation time raised to configured lead time');
check((int) $booking->subsequent_time === Settings::bufferSeconds(), 'subsequent time raised to configured buffer');

$conference = Conference::findByBooking($booking->id);
check($conference !== null && $conference->status === 'active', 'conference created for normal booking');
check(strlen($conference->token) === 32 && $conference->token !== $conference->id, 'conference has separate join token');
check(!str_contains($conference->attendee_pw_hash . $conference->moderator_pw_hash, 'test'), 'only password hashes stored');

$mails = mailsTo($dozent->email);
check(count($mails) === 1, 'booked person got one mail');
$body = $mails ? mailBody($mails[0]['ID']) : '';
check(str_contains($body, '/plugins.php/exclusivebbb/join/index/' . $conference->token), 'mail contains join link');
preg_match('/Teilnehmende:\s+(\S+)/', $body, $attendee);
preg_match('/Moderierende:\s+(\S+)/', $body, $moderator);
check(
    isset($attendee[1], $moderator[1])
    && Passwords::roleFor($attendee[1], $conference->attendee_pw_hash, $conference->moderator_pw_hash) === Passwords::ROLE_ATTENDEE
    && Passwords::roleFor($moderator[1], $conference->attendee_pw_hash, $conference->moderator_pw_hash) === Passwords::ROLE_MODERATOR,
    'mailed passwords match stored hashes and roles'
);

// --- 2. permissions ----------------------------------------------------------
check(ConferenceService::canEdit($conference, $dozent), 'booked person may edit');
check(ConferenceService::canEdit($conference, $admin), 'resource admin may edit');
check(!ConferenceService::canEdit($conference, User::findByUsername('test_autor')), 'unrelated user may not edit');
check(ConferenceService::canManage($admin), 'resource admin may open management view');
check(!ConferenceService::canManage(User::findByUsername('test_autor')), 'unrelated user may not open management view');

// --- 3. buffer: a booking starting 5 minutes after the end is refused -------
$refused = false;
try {
    $too_close = $room->createBooking($admin, $dozent->id, [['begin' => $day + 7200 + 300, 'end' => $day + 10800]]);
    $cleanup[] = $too_close;
} catch (Exception $e) {
    $refused = true;
}
check($refused, 'booking inside the overrun buffer is refused');

// a booking after buffer + preparation fits
$after = $day + 7200 + Settings::bufferSeconds() + Settings::prepSeconds();
$next = $room->createBooking($admin, $dozent->id, [['begin' => $after, 'end' => $after + 3600]]);
$cleanup[] = $next;
check(Conference::findByBooking($next->id) !== null, 'booking after buffer and lead time is accepted');

// --- 4. reservations get no conference --------------------------------------
$reservation = $room->createBooking(
    $admin, $dozent->id, [['begin' => strtotime('+3 days 10:00'), 'end' => strtotime('+3 days 11:00')]],
    null, 0, null, 0, '', '', ResourceBooking::TYPE_RESERVATION
);
$cleanup[] = $reservation;
check(Conference::findByBooking($reservation->id) === null, 'reservation gets no conference');

// --- 5. weekly series: one conference, one occurrence per week --------------
$series_begin = strtotime('+7 days 14:00');
$series = $room->createBooking(
    $admin, $dozent->id, [['begin' => $series_begin, 'end' => $series_begin + 5400]],
    new DateInterval('P1W'), 0, (new DateTime())->setTimestamp($series_begin + 3 * 7 * 86400 + 7200)
);
$cleanup[] = $series;
$series_conference = Conference::findByBooking($series->id);
check($series_conference && count($series_conference->getOccurrences()) === 4, 'weekly series has one conference with 4 occurrences');

// --- 6. joining outside the window ------------------------------------------
try {
    JoinService::join($conference, 'Gast', $attendee[1] ?? '', null, '192.0.2.10', time());
    check(false, 'join before window is refused');
} catch (JoinDeniedException $e) {
    check($e->reason === JoinService::DENY_NOT_YET, 'join before window is refused (not_yet)');
}

// --- 7. booking covering now: join, enforce -----------------------------------
$now_begin = time() - 600;
$now_booking = $room->createBooking($admin, $dozent->id, [['begin' => $now_begin, 'end' => $now_begin + 3600]], null, 0, null, 0, 'Senatssitzung (Test)');
if (!$keep_now) {
    $cleanup[] = $now_booking;
}
$now_conference = Conference::findByBooking($now_booking->id);
$now_mail = mailsTo($dozent->email);
$now_body = '';
foreach ($now_mail as $mail) {
    $text = mailBody($mail['ID']);
    if (str_contains($text, $now_conference->token)) {
        $now_body = $text;
    }
}
preg_match('/Teilnehmende:\s+(\S+)/', $now_body, $now_attendee);
preg_match('/Moderierende:\s+(\S+)/', $now_body, $now_moderator);

try {
    JoinService::join($now_conference, 'Gast', 'falsch', null, '192.0.2.10', time());
    check(false, 'wrong password is refused');
} catch (JoinDeniedException $e) {
    check($e->reason === JoinService::DENY_WRONG_PASSWORD, 'wrong password is refused');
}

$url = JoinService::join($now_conference, 'Erika Gast', $now_attendee[1], null, '192.0.2.10', time());
parse_str(parse_url($url, PHP_URL_QUERY), $params);
check(str_starts_with($url, 'http://bbb-node1:8090/bigbluebutton/api/join?'), 'join URL points to the booked node');
check($params['role'] === 'VIEWER' && !isset($params['password']), 'attendee joins with role=VIEWER, no password sent');

$url = JoinService::join($now_conference, 'Max Moderation', $now_moderator[1], null, '192.0.2.11', time());
parse_str(parse_url($url, PHP_URL_QUERY), $params);
check($params['role'] === 'MODERATOR', 'moderator password gives role=MODERATOR');

$calls = json_decode(http(NODE1_MOCK . '/mock/calls'), true);
$creates = array_values(array_filter($calls, fn($call) => $call['call'] === 'create'));
check(count($creates) >= 1, 'meeting created lazily on first join');
check(
    ($creates[0]['params']['meta_exbbb-conference'] ?? null) === $now_conference->id
    && ($creates[0]['params']['record'] ?? null) === 'false'
    && (int) ($creates[0]['params']['duration'] ?? 0) > 0,
    'create carries conference metadata, record=false and duration'
);

// a foreign meeting started with the node secret is ended by the enforcer
$foreign = new ExclusiveBBB\BBB\Client(NODE1_MOCK, 'node1-dev-secret');
$foreign->create(['meetingID' => 'foreign-meeting', 'name' => 'Fremd']);
$result = Enforcer::run(time());
check($result[$nodes[0]->id]['ended'] === 1, 'enforcer ends the foreign meeting only');
$running = array_column($nodes[0]->getClient()->getMeetings(), 'meetingID');
check(!in_array('foreign-meeting', $running, true) && count($running) === 1, 'booked meeting keeps running');

// after the buffer the booked meeting is ended
$result = Enforcer::run(time() + 3600 + Settings::bufferSeconds());
check($result[$nodes[0]->id]['ended'] === 1, 'enforcer ends the meeting after end + buffer');

if ($keep_now) {
    file_put_contents('/tmp/exbbb-now.json', json_encode([
        'token'     => $now_conference->token,
        'attendee'  => $now_attendee[1],
        'moderator' => $now_moderator[1],
        'id'        => $now_conference->id,
        'resource'  => $room->id,
    ]));
    echo "kept booking {$now_booking->id}, details in /tmp/exbbb-now.json\n";
}

// --- 8. room request for a course date, resolved by Raumvergabe --------------
$course = Course::findOneBySQL("Seminar_id IN (SELECT Seminar_id FROM seminar_user WHERE user_id = ? AND status = 'dozent')", [$dozent->id]);
$date = new CourseDate();
$date->range_id = $course->id;
$date->autor_id = $dozent->id;
$date->date = strtotime('+5 days 16:00');
$date->end_time = strtotime('+5 days 18:00');
$date->date_typ = 1;
$date->store();

$request = new RoomRequest();
$request->course_id = $course->id;
$request->termin_id = $date->id;
$request->resource_id = $room->id;
$request->user_id = $dozent->id;
$request->comment = 'Antrittsvorlesung mit ca. 300 Gästen';
$request->store();
check(!$request->isNew(), 'lecturer files a room request for the node');

$tutor = User::findByUsername('test_tutor');
$student = User::findByUsername('test_autor');
http(MAILPIT . '/messages', 'DELETE');

$request_bookings = $room->createBookingFromRequest($admin, $request);
$request_booking = $request_bookings[0] ?? null;
$cleanup[] = $request_booking;
$course_conference = $request_booking ? Conference::findByBooking($request_booking->id) : null;
check($course_conference !== null, 'resolving the request creates a conference');
check($request_booking && $request_booking->range_id === $date->id, 'booking is attached to the course date');
check(ConferenceService::requesterIds($request_booking) === [$dozent->id], 'requester is found from the request');
check(ConferenceService::canEdit($course_conference, $tutor), 'course tutor may edit the conference');
check(!ConferenceService::canEdit($course_conference, $student), 'course student may not edit');
check(JoinService::implicitRole($course_conference, $student) === Passwords::ROLE_ATTENDEE, 'course student joins as attendee without password');
check(JoinService::implicitRole($course_conference, $dozent) === Passwords::ROLE_MODERATOR, 'course lecturer joins as moderator without password');
check(JoinService::implicitRole($course_conference, User::findByUsername('test_admin')) === Passwords::ROLE_MODERATOR, 'Raumvergabe joins as moderator');
$found = false;
foreach (mailsTo($dozent->email) as $mail) {
    $found = $found || str_contains(mailBody($mail['ID']), $course_conference->token);
}
check($found, 'lecturer receives the join link for the course date');
check($course_conference->getDisplayName() === $course->getFullName(), 'conference is named after the course by default');

$date->delete();
check(Conference::find($course_conference->id)->status === Conference::STATUS_CANCELLED, 'deleting the course date cancels the conference');
$request->delete();

// --- 9. deleting a booking cancels its conference ---------------------------
$booking->delete();
$conference = Conference::find($conference->id);
check($conference->status === Conference::STATUS_CANCELLED, 'deleting the booking cancels the conference');
check(count(mailsTo($dozent->email)) >= 3, 'cancellation mail sent');

echo $failures ? "\n$failures FAILED\n" : "\nALL PASSED\n";
exit($failures ? 1 : 0);
