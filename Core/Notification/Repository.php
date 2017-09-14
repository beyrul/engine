<?php
namespace Minds\Core\Notification;

use Cassandra;

use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Data;
use Minds\Core\Data\Cassandra\Prepared;
use Minds\Entities;

class Repository
{
    const NOTIFICATION_TTL = 30 * 24 * 60 * 60;

    protected $owner;

    public function __construct($db = null)
    {
        $this->db = $db ?: Di::_()->get('Database\Cassandra\Cql');
    }

    public function setOwner($guid)
    {
        if (is_object($guid)) {
            $guid = $guid->guid;
        } elseif (is_array($guid)) {
            $guid = $guid['guid'];
        }

        $this->owner = $guid;

        return $this;
    }

    public function getAll($type = null, array $options = [])
    {
        if (!$this->owner) {
            throw new \Exception('Should call to setOwner() first');
        }

        $options = array_merge([
            'limit' => 12,
            'offset' => ''
        ], $options);

        $template = "SELECT * FROM notifications WHERE owner_guid = ?";
        $values = [ new Cassandra\Varint($this->owner) ];
        $allowFiltering = false;

        if ($type) {
            // TODO: Switch template to materialized view
            $template .= " AND type = ?";
            $values[] = (string) $type;
            $allowFiltering = true;
        }

        if ($options['offset']) {
            // @note: Using <= because order is DESC, and offset duplication is handled by frontend
            $template .= " AND guid <= ?";
            $values[] = new Cassandra\Varint($options['offset']);
            $allowFiltering = true;
        }

        if ($options['limit']) {
            $template .= " LIMIT ?";
            $values[] = (int) $options['limit'];
        }

        if ($allowFiltering) {
            $template .= " ALLOW FILTERING";
        }

        $query = new Prepared\Custom();
        $query->query($template, $values);

        $notifications = [];

        try {
            $result = $this->db->request($query);

            foreach ($result as $row) {
                $notification = new Entities\Notification();
                $notification->loadFromArray($row['data']);
                $notifications[] = $notification;
            }
        } catch (\Exception $e) {
            // TODO: Log or warning
        }

        return $notifications;
    }

    public function getEntity($guid)
    {
        if (!$guid) {
            return false;
        }

        if (!$this->owner) {
            throw new \Exception('Should call to setOwner() first');
        }

        $template = "SELECT * FROM notifications WHERE owner_guid = ? AND guid = ? LIMIT ?";
        $values = [ new Cassandra\Varint($this->owner), new Cassandra\Varint($guid), 1 ];

        $query = new Prepared\Custom();
        $query->query($template, $values);

        $notification = false;

        try {
            $result = $this->db->request($query);

            $row = $result[0];

            if ($row) {
                $notification = new Entities\Notification();
                $notification->loadFromArray($row['data']);
            }
        } catch (\Exception $e) {
            // TODO: Log or warning
        }

        return $notification;
    }

    public function store($data)
    {
        if (!$data['guid']) {
            return false;
        }

        if (!$this->owner) {
            throw new \Exception('Should call to setOwner() first');
        }

        $template = "INSERT INTO notifications (owner_guid, guid, type, data) VALUES (?, ?, ?, ?) USING TTL ?";
        $values = [
            new Cassandra\Varint($this->owner),
            new Cassandra\Varint($data['guid']),
            $data['filter'] ?: 'other',
            json_encode($data),
            static::NOTIFICATION_TTL
        ];

        $query = new Prepared\Custom();
        $query->query($template, $values);

        try {
            $success = $this->db->request($query);
        } catch (\Exception $e) {
            return false;
        }

        return $success;
    }

    public function delete($guid)
    {
        if (!$guid) {
            return false;
        }

        if (!$this->owner) {
            throw new \Exception('Should call to setOwner() first');
        }

        $template = "DELETE FROM notifications WHERE owner_guid = ? AND guid = ? LIMIT ?";
        $values = [ new Cassandra\Varint($this->owner), new Cassandra\Varint($guid), 1];

        $query = new Prepared\Custom();
        $query->query($template, $values);

        try {
            $success = $this->db->request($query);
        } catch (\Exception $e) {
            return false;
        }

        return (bool) $success;
    }

    public function count()
    {
        if (!$this->owner) {
            throw new \Exception('Should call to setOwner() first');
        }

        $template = "SELECT COUNT(*) FROM notifications WHERE owner_guid = ?";
        $values = [ new Cassandra\Varint($this->owner) ];

        $query = new Prepared\Custom();
        $query->query($template, $values);

        try {
            $result = $this->db->request($query);
            $count = (int) $result[0]['count'];
        } catch (\Exception $e) {
            $count = 0;
        }

        return $count;
    }
}