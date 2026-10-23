<?php

namespace ExclusiveBBB\Models;

use ExclusiveBBB\BBB\Client;

/**
 * An exclusively bookable BigBlueButton node, mapped 1:1 to a Stud.IP resource.
 *
 * @property int    $id
 * @property string $resource_id
 * @property string $url
 * @property string $secret
 * @property string $algorithm
 * @property int    $max_participants
 * @property int    $active
 * @property int|null    $last_check
 * @property string|null $last_status
 * @property int    $mkdate
 * @property int    $chdate
 * @property \Resource $resource
 */
class Node extends \SimpleORMap
{
    protected static function configure($config = [])
    {
        $config['db_table'] = 'exbbb_nodes';
        $config['belongs_to']['resource'] = [
            'class_name'  => \Resource::class,
            'foreign_key' => 'resource_id',
        ];
        parent::configure($config);
    }

    public static function findByResource(string $resource_id): ?self
    {
        return self::findOneBySQL('resource_id = ?', [$resource_id]);
    }

    public static function isNodeResource(string $resource_id): bool
    {
        return self::countBySQL('resource_id = ?', [$resource_id]) > 0;
    }

    /**
     * @return self[]
     */
    public static function findActive(): array
    {
        return self::findBySQL('active = 1 ORDER BY id');
    }

    public function getClient(): Client
    {
        return new Client($this->url, $this->secret, $this->algorithm ?: 'sha256');
    }

    public function getName(): string
    {
        return $this->resource ? (string) $this->resource->name : ('#' . $this->id);
    }
}
