<?php

namespace WikiMedia\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use PlasmaConduit\option\None;
use PlasmaConduit\option\Option;
use PlasmaConduit\option\Some;
use WikiMedia\OAuth\User;

class UsersRepository
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function updateUser(User $user)
    {
        $properties = [
            'name' => $user->name,
            'edit_count' => isset($user->extra['editCount']) ? $user->extra['editCount'] : 0,
            'scoring_interface' => isset($user->extra['scoringInterface']) ? $user->extra['scoringInterface'] : 'classic',
        ];

        if ($this->userExists($user)) {
            $this->db->update('users', $properties, ['id' => $user->uid]);
        } else {
            $properties['id'] = $user->uid;
            $properties['created'] = time();
            $this->db->insert('users', $properties);
        }
    }

    public function userExists(User $user)
    {
        $sql = 'SELECT 1 FROM users WHERE id = ?';

        return $this->db->fetchColumn($sql, [$user->uid]) === '1';
    }

    /**
     * @param string $name
     *
     * @return Option<User>
     */
    public function getUserByName($name)
    {
        return $this->createUserFromCondition('name = ?', [$name]);
    }

    /**
     * @param int $id
     *
     * @return Option<User>
     */
    public function getUserById($id)
    {
        return $this->createUserFromCondition('id = ?', [$id]);
    }

    private function createUserFromCondition($condition, $values)
    {
        $sql = "SELECT id, name, edit_count, scoring_interface FROM users WHERE $condition";
        $row = $this->db->fetchAssoc($sql, $values);
        if (!$row) {
            return new None();
        }

        $user = new User();
        $user->uid = (int) $row['id'];
        $user->name = $row['name'];
        $user->extra = [
            'editCount' => $row['edit_count'],
            'scoringInterface' => $row['scoring_interface'],
        ];

        return new Some($user);
    }
}
