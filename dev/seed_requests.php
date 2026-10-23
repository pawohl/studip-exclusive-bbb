<?php
/**
 * Dev seed: a synthetic lecturer with a course, upcoming course dates and open
 * room requests for the BBB node rooms, to be decided by Raumvergabe (test_admin).
 * Run after dev/seed.php, inside the web container:
 *   php public/plugins_packages/exclusivebbb/ExclusiveBBB/dev/seed_requests.php
 * Idempotent: skips everything that already exists. Synthetic data only.
 */

require '/var/www/studip/cli/studip_cli_env.inc.php';

const USERNAME = 'prof_musterfrau';
const COURSE_NAME = 'Ringvorlesung Digitale Souveränität';
const INSTITUTE_NAME = 'Test Einrichtung';

$institute = Institute::findOneBySQL('Name = ?', [INSTITUTE_NAME]);
$rooms = [
    Resource::findOneBySQL("name = 'BBB Exklusiv 1'"),
    Resource::findOneBySQL("name = 'BBB Exklusiv 2'"),
];
if (!$institute || !$rooms[0] || !$rooms[1]) {
    fwrite(STDERR, "Run dev/seed.php first (node rooms missing) or demo data is incomplete.\n");
    exit(1);
}

// --- lecturer ----------------------------------------------------------------
$lecturer = User::findByUsername(USERNAME);
if (!$lecturer) {
    $lecturer = new User();
    $lecturer->username = USERNAME;
    $lecturer->password = UserManagement::getPwdHasher()->HashPassword('testing');
    $lecturer->perms = 'dozent';
    $lecturer->vorname = 'Erika';
    $lecturer->nachname = 'Musterfrau';
    $lecturer->email = 'erika.musterfrau@example.org';
    $lecturer->auth_plugin = 'standard';
    $lecturer->visible = 'yes';
    $lecturer->info->title_front = 'Prof. Dr.';
    $lecturer->store();
    echo "created lecturer {$lecturer->username} / testing\n";
}
UserConfig::get($lecturer->id)->store('TERMS_ACCEPTED', 1);

if (!InstituteMember::countBySQL('user_id = ? AND institut_id = ?', [$lecturer->id, $institute->id])) {
    $membership = new InstituteMember();
    $membership->user_id = $lecturer->id;
    $membership->institut_id = $institute->id;
    $membership->inst_perms = 'dozent';
    $membership->store();
}

// --- course with upcoming dates ----------------------------------------------
$course = Course::findOneBySQL('Name = ?', [COURSE_NAME]);
if (!$course) {
    $course = new Course();
    $course->name = COURSE_NAME;
    $course->veranstaltungsnummer = 'RV-2026';
    $course->status = 1;
    $course->institut_id = $institute->id;
    $course->visible = 1;
    $course->lesezugriff = 1;
    $course->schreibzugriff = 1;
    $course->beschreibung = 'Öffentliche Ringvorlesung mit externen Gästen und Livestream-Publikum.';
    $course->store();
    DBManager::get()->execute(
        'INSERT IGNORE INTO semester_courses (semester_id, course_id) VALUES (?, ?)',
        [Semester::findCurrent()->id, $course->id]
    );
    DBManager::get()->execute(
        'INSERT IGNORE INTO seminar_inst (seminar_id, institut_id) VALUES (?, ?)',
        [$course->id, $institute->id]
    );
    $course->addMember($lecturer, 'dozent', false, false);
    // a student, to try joining without password as a course member
    $student = User::findByUsername('test_autor');
    if ($student) {
        $course->addMember($student, 'autor', false, false);
    }
    echo "created course {$course->name} ({$course->id})\n";
}

// three Tuesday sessions 16:00-18:00, starting in two weeks
$dates = CourseDate::findBySQL('range_id = ? AND date > UNIX_TIMESTAMP() ORDER BY date', [$course->id]);
if (count($dates) < 3) {
    $tuesday = strtotime('next tuesday', strtotime('+2 weeks'));
    for ($week = count($dates); $week < 3; $week++) {
        $day = strtotime("+{$week} weeks", $tuesday);
        $date = new CourseDate();
        $date->range_id = $course->id;
        $date->autor_id = $lecturer->id;
        $date->date = strtotime('16:00', $day);
        $date->end_time = strtotime('18:00', $day);
        $date->date_typ = 1;
        $date->store();
        $dates[] = $date;
    }
    echo "created course dates\n";
}

// --- open requests by the lecturer -------------------------------------------
$open = ResourceRequest::countBySQL('user_id = ? AND closed = 0', [$lecturer->id]);
if ($open > 0) {
    echo "lecturer already has {$open} open request(s), nothing to add\n";
    exit(0);
}

// the requests are filed as the lecturer
$GLOBALS['user'] = new Seminar_User($lecturer);
$GLOBALS['perm'] = new Seminar_Perm();

function newRequest(Resource $room, User $lecturer, string $comment): RoomRequest
{
    $request = new RoomRequest();
    $request->resource_id = $room->id;
    $request->category_id = $room->category_id;
    $request->user_id = $lecturer->id;
    $request->comment = $comment;
    $request->closed = ResourceRequest::STATE_OPEN;
    return $request;
}

// 1. single course date on node 1
$request = newRequest($rooms[0], $lecturer, 'Auftaktveranstaltung der Ringvorlesung, ca. 400 Teilnehmende inkl. externer Gäste.');
$request->course_id = $course->id;
$request->termin_id = $dates[0]->id;
$request->store();
$request->setProperty('seats', 400);
echo 'request (course date ' . date('d.m.Y H:i', $dates[0]->date) . ") -> {$rooms[0]->name}\n";

// 2. two further course dates on node 2
$request = newRequest($rooms[1], $lecturer, 'Folgetermine mit Podiumsdiskussion, jeweils ca. 250 Teilnehmende.');
$request->course_id = $course->id;
$request->store();
foreach ([$dates[1], $dates[2]] as $date) {
    $appointment = new ResourceRequestAppointment();
    $appointment->request_id = $request->id;
    $appointment->appointment_id = $date->id;
    $appointment->store();
}
$request->setProperty('seats', 250);
echo 'request (course dates ' . date('d.m.', $dates[1]->date) . ', ' . date('d.m.Y', $dates[2]->date) . ") -> {$rooms[1]->name}\n";

// 3. event without course (e.g. a ceremony with many external guests)
$day = strtotime('next friday', strtotime('+5 weeks'));
$request = newRequest($rooms[0], $lecturer, 'Festakt zur Emeritierung (ohne Veranstaltung), hybride Übertragung, ca. 600 Gäste.');
$request->begin = strtotime('14:00', $day);
$request->end = strtotime('17:00', $day);
$request->store();
$request->setProperty('seats', 600);
echo 'request (event ' . date('d.m.Y H:i', $request->begin) . ") -> {$rooms[0]->name}\n";
