<?php
/**
 * Dev seed: creates the node category, a "Virtuell" building, two node rooms
 * mapped to the BBB mocks and gives test_admin admin rights on them.
 * Idempotent. Synthetic data only. Run inside the web container:
 *   php public/plugins_packages/exclusivebbb/ExclusiveBBB/dev/seed.php
 */

require '/var/www/studip/cli/studip_cli_env.inc.php';

StudipAutoloader::addAutoloadPath(__DIR__ . '/../lib', 'ExclusiveBBB');

use ExclusiveBBB\Models\Node;

const CATEGORY_NAME = 'Videokonferenz-Server (exklusiv)';
const NODES = [
    ['BBB Exklusiv 1', 'http://bbb-node1:8090/bigbluebutton/', 'node1-dev-secret'],
    ['BBB Exklusiv 2', 'http://bbb-node2:8090/bigbluebutton/', 'node2-dev-secret'],
];

$db = DBManager::get();

/**
 * ResourceCategory::createResource() fails the building's parent check in the
 * CLI context, so resources are created directly here.
 */
function createResource(ResourceCategory $category, string $name, string $description, string $parent_id): Resource
{
    $resource = new $category->class_name();
    $resource->category_id = $category->id;
    $resource->parent_id = $parent_id;
    $resource->name = $name;
    $resource->description = $description;
    $resource->store();
    return $resource;
}

// category with the same properties as the demo "Übungsraum" category
$category = ResourceCategory::findOneBySQL('name = ?', [CATEGORY_NAME]);
if (!$category) {
    $template = ResourceCategory::findOneBySQL("class_name = 'Room' ORDER BY name DESC");
    $category = new ResourceCategory();
    $category->name = CATEGORY_NAME;
    $category->description = 'Exklusiv buchbare BigBlueButton-Server';
    $category->class_name = 'Room';
    $category->system = 0;
    $category->iconnr = 1;
    $category->store();
    $db->execute(
        'INSERT INTO resource_category_properties (category_id, property_id, requestable, protected, system, form_text, mkdate, chdate)
         SELECT ?, property_id, requestable, protected, system, form_text, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
         FROM resource_category_properties WHERE category_id = ?',
        [$category->id, $template->id]
    );
    echo "created category {$category->id}\n";
}

$location = Resource::findOneBySQL(
    "category_id IN (SELECT id FROM resource_categories WHERE class_name = 'Location') ORDER BY mkdate"
);
$building = Resource::findOneBySQL("name = 'Virtuell'");
if (!$building) {
    $building_category = ResourceCategory::findOneBySQL("class_name = 'Building'");
    $building = createResource($building_category, 'Virtuell', 'Virtuelle Räume', $location->id);
    echo "created building {$building->id}\n";
}

$admin = User::findByUsername('test_admin');

foreach (NODES as [$name, $url, $secret]) {
    $room = Resource::findOneBySQL('name = ?', [$name]);
    if (!$room) {
        $room = createResource($category, $name, 'BigBlueButton-Server, exklusiv buchbar', $building->id);
        $room->setProperty('seats', 500);
        $room->setProperty('booking_plan_is_public', 1);
        echo "created room {$room->id} ($name)\n";
    }
    $room->requestable = 1;
    $room->store();

    if ($admin && !ResourcePermission::countBySQL('resource_id = ? AND user_id = ?', [$room->id, $admin->id])) {
        $permission = new ResourcePermission();
        $permission->resource_id = $room->id;
        $permission->user_id = $admin->id;
        $permission->perms = 'admin';
        $permission->store();
    }

    $node = Node::findByResource($room->id) ?: new Node();
    $node->resource_id = $room->id;
    $node->url = $url;
    $node->secret = $secret;
    $node->algorithm = 'sha256';
    $node->max_participants = 0;
    $node->active = 1;
    $node->store();
    echo "node {$node->id} -> {$name}\n";
}

// demo users: skip the first-login prompts (terms of use, visibility) for E2E tests
foreach (['test_admin', 'test_dozent', 'test_tutor', 'test_autor', 'root@studip'] as $username) {
    $demo_user = User::findByUsername($username);
    if ($demo_user) {
        if ($demo_user->visible === 'unknown') {
            $demo_user->visible = 'yes';
            $demo_user->store();
        }
        UserConfig::get($demo_user->id)->store('TERMS_ACCEPTED', 1);
    }
}
