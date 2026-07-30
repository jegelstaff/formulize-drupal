<?php

declare(strict_types=1);

namespace Drupal\formulize;

use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors Drupal users and roles into Formulize.
 *
 * One way only: Drupal is the system of record for identity, and Formulize's session handler trusts
 * whichever Drupal user is in scope. What this class maintains is the other half of that arrangement -
 * a Formulize account and group membership for each Drupal user, plus the rows in Formulize's resource
 * mapping table that connect the two sets of IDs.
 *
 * Passwords are deliberately never synchronised. Drupal only has a hash, and Formulize could not
 * validate a Drupal hash anyway; single sign-on means no one ever authenticates against the Formulize
 * account directly. New accounts are given a random password so no account is left with an empty one.
 */
class FormulizeUserSync {

  /**
   * Drupal's built in roles, mapped to the Formulize groups that already exist for the same purpose.
   *
   * The pairings look transposed because they are: Formulize numbers its groups 1 = Webmasters,
   * 2 = Registered Users, 3 = Anonymous, whereas Drupal's equivalents run in the other order.
   */
  private const SEEDED_ROLES = [
    RoleInterface::ANONYMOUS_ID => 3,
    RoleInterface::AUTHENTICATED_ID => 2,
    'administrator' => 1,
  ];

  /**
   * Drupal's superuser, mapped to Formulize's.
   */
  private const SEEDED_USERS = [1 => 1];

  /**
   * Value stored in a Formulize group's type column for groups this module creates.
   */
  private const GROUP_TYPE = 'Drupal role';

  public function __construct(
    private readonly FormulizeBootstrap $bootstrap,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Create the fixed mappings between Drupal's built in roles and Formulize's built in groups.
   */
  public function seedMappings(): void {
    $this->bootstrap->call(function (): void {
      foreach (self::SEEDED_ROLES as $roleId => $groupId) {
        \Formulize::createResourceMapping(\Formulize::GROUP_RESOURCE, $roleId, $groupId);
      }
      foreach (self::SEEDED_USERS as $drupalUid => $formulizeUid) {
        \Formulize::createResourceMapping(\Formulize::USER_RESOURCE, $drupalUid, $formulizeUid);
      }
      $this->logger->info('Seeded the Formulize mappings for Drupal\'s built in roles and superuser.');
    });
  }

  /**
   * Create a Formulize group for a Drupal role.
   *
   * Role IDs are strings in Drupal. That needs no special handling: Formulize's mapping table stores a
   * non-numeric external ID in its external_id_string column, and reads it back the same way.
   */
  public function createGroup(RoleInterface $role): void {
    if ($this->isSeeded($role->id())) {
      return;
    }
    $this->bootstrap->call(function () use ($role): void {
      $created = \Formulize::createGroup($role->id(), $role->label(), $role->label(), self::GROUP_TYPE);
      if ($created) {
        $this->logger->info('Created Formulize group for role @role.', ['@role' => $role->id()]);
      }
    });
  }

  /**
   * Rename a Formulize group after its Drupal role was relabelled.
   */
  public function renameGroup(RoleInterface $role): void {
    if ($this->isSeeded($role->id())) {
      return;
    }
    $this->bootstrap->call(function () use ($role): void {
      // A role that predates this module having been configured may have no group yet.
      if (\Formulize::getXoopsResourceID(\Formulize::GROUP_RESOURCE, $role->id()) === NULL) {
        \Formulize::createGroup($role->id(), $role->label(), $role->label(), self::GROUP_TYPE);
        return;
      }
      \Formulize::renameGroup($role->id(), $role->label());
    });
  }

  /**
   * Delete the Formulize group belonging to a deleted Drupal role.
   */
  public function deleteGroup(RoleInterface $role): void {
    if ($this->isSeeded($role->id())) {
      // Never delete Formulize's built in groups, which we only borrowed a mapping to.
      return;
    }
    $this->bootstrap->call(function () use ($role): void {
      \Formulize::deleteGroup($role->id());
      $this->logger->info('Deleted Formulize group for role @role.', ['@role' => $role->id()]);
    });
  }

  /**
   * Create a Formulize account for a Drupal user, and put it in the right groups.
   */
  public function createUser(UserInterface $account): void {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return;
    }

    $this->bootstrap->call(function () use ($account, $uid): void {
      if (\Formulize::getXoopsResourceID(\Formulize::USER_RESOURCE, $uid) !== NULL) {
        // Already mapped, so treat this as an update instead of creating a duplicate.
        $this->pushUserFields($account);
        $this->syncGroups($account, []);
        return;
      }

      $user = new \FormulizeUser([
        'uid' => $uid,
        'uname' => $account->getAccountName(),
        'login_name' => $account->getAccountName(),
        'email' => $account->getEmail(),
        'timezone_offset' => $this->timezoneOffset($account),
      ]);

      if (!\Formulize::createUser($user)) {
        $this->logger->error('Could not create a Formulize account for Drupal user @uid (@name).', [
          '@uid' => $uid,
          '@name' => $account->getAccountName(),
        ]);
        return;
      }

      $this->setRandomPassword($uid);
      $this->pushUserFields($account);
      $this->syncGroups($account, []);

      $this->logger->info('Created Formulize account for Drupal user @uid (@name).', [
        '@uid' => $uid,
        '@name' => $account->getAccountName(),
      ]);
    });
  }

  /**
   * Push a changed Drupal user through to Formulize, including group membership.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user as saved.
   * @param array $previousRoles
   *   The roles the user had before this save.
   */
  public function updateUser(UserInterface $account, array $previousRoles): void {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return;
    }

    $this->bootstrap->call(function () use ($account, $uid, $previousRoles): void {
      if (\Formulize::getXoopsResourceID(\Formulize::USER_RESOURCE, $uid) === NULL) {
        // No Formulize account yet, so make one rather than dropping the change.
        $this->createUser($account);
        return;
      }
      $this->pushUserFields($account);
      $this->syncGroups($account, $previousRoles);
    });
  }

  /**
   * Remove a Drupal user's Formulize account.
   */
  public function deleteUser(UserInterface $account): void {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return;
    }
    $this->bootstrap->call(function () use ($uid): void {
      if (\Formulize::deleteUser($uid)) {
        $this->logger->info('Deleted the Formulize account for Drupal user @uid.', ['@uid' => $uid]);
      }
    });
  }

  /**
   * Write the user's current field values to their Formulize account.
   *
   * Note what is absent: 'pass'. Formulize's updateUser() sets whatever keys it is handed, so passing
   * Drupal's password hash would write a hash Formulize can never validate into its own password
   * column - which is what the Drupal 7 module did on every user edit.
   */
  private function pushUserFields(UserInterface $account): void {
    \Formulize::updateUser((int) $account->id(), [
      'uname' => $account->getAccountName(),
      'login_name' => $account->getAccountName(),
      'email' => $account->getEmail(),
      'timezone_offset' => $this->timezoneOffset($account),
      // Blocking a Drupal user should take their Formulize access away too.
      'level' => $account->isActive() ? 1 : 0,
    ]);
  }

  /**
   * Add and remove Formulize group memberships to match the user's Drupal roles.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user.
   * @param array $previousRoles
   *   Roles held before this save; anything in here and not in the current roles is removed.
   */
  private function syncGroups(UserInterface $account, array $previousRoles): void {
    $uid = (int) $account->id();
    $current = $account->getRoles();

    // Add only memberships the account does not already have. Formulize's member handler inserts a
    // membership row without checking for one, so driving additions off the role diff alone would
    // stack up duplicate rows every time a catch-up sync ran. Reading the current membership instead
    // makes this safe to run repeatedly.
    $existing = $this->formulizeGroupIds($uid);
    foreach ($current as $roleId) {
      $groupId = \Formulize::getXoopsResourceID(\Formulize::GROUP_RESOURCE, $roleId);
      if ($groupId !== NULL && !in_array((int) $groupId, $existing, TRUE)) {
        \Formulize::addUserToGroup($uid, $roleId);
      }
    }

    // Removals stay driven by the role diff. Dropping every Formulize group that has no matching
    // Drupal role would also strip groups an administrator assigned inside Formulize by hand.
    foreach (array_diff($previousRoles, $current) as $roleId) {
      \Formulize::removeUserFromGroup($uid, $roleId);
    }
  }

  /**
   * The Formulize group IDs a Drupal user's mapped account currently belongs to.
   *
   * @return int[]
   */
  private function formulizeGroupIds(int $drupalUid): array {
    $formulizeUid = \Formulize::getXoopsResourceID(\Formulize::USER_RESOURCE, $drupalUid);
    if ($formulizeUid === NULL) {
      return [];
    }
    $groups = xoops_gethandler('member')->getGroupsByUser($formulizeUid);
    return is_array($groups) ? array_map('intval', $groups) : [];
  }

  /**
   * Give a freshly created Formulize account a random password.
   *
   * Formulize's account creation API does not take one, which would leave the password column empty.
   * Nobody ever needs to log into these accounts directly, so a random value that is never recorded
   * anywhere is exactly right.
   */
  private function setRandomPassword(int $uid): void {
    try {
      $password = new \icms_core_Password();
      \Formulize::updateUser($uid, [
        'pass' => $password->hashPassword(bin2hex(random_bytes(32))),
        'salt' => $password->createSalt(),
        'enc_type' => $GLOBALS['icmsConfigUser']['enc_type'] ?? 1,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not set a random Formulize password for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * The user's UTC offset in hours, which is the form Formulize stores.
   *
   * Drupal holds a timezone name, so it has to be converted. The Drupal 7 module divided the name by
   * 3600 and therefore recorded zero for everybody.
   */
  private function timezoneOffset(UserInterface $account): float {
    $name = $account->getTimeZone();
    if (!is_string($name) || $name === '') {
      $name = date_default_timezone_get();
    }
    try {
      $zone = new \DateTimeZone($name);
      return $zone->getOffset(new \DateTime('now', $zone)) / 3600;
    }
    catch (\Throwable) {
      return 0.0;
    }
  }

  /**
   * Whether a role is one of the built in ones with a fixed mapping.
   */
  private function isSeeded(?string $roleId): bool {
    return $roleId !== NULL && array_key_exists($roleId, self::SEEDED_ROLES);
  }

  /**
   * Whether Formulize's resource mapping table is present.
   *
   * Without it there is nothing to connect Drupal IDs to Formulize IDs, so single sign-on cannot work.
   * It ships with Formulize's own schema; this module does not create it.
   */
  public function mappingTableExists(): bool {
    return (bool) $this->bootstrap->call(static function (): bool {
      $db = $GLOBALS['xoopsDB'];
      $table = $db->prefix('formulize_resource_mapping');
      return $db->query('SELECT 1 FROM ' . $table . ' LIMIT 1') !== FALSE;
    }, FALSE);
  }

  /**
   * How many Formulize screens exist, for the status report.
   */
  public function screenCount(): ?int {
    $count = $this->bootstrap->call(static function (): int {
      $screens = \Formulize::getScreens();
      if (!is_array($screens)) {
        return 0;
      }
      unset($screens[0]);
      return count($screens);
    });
    return is_int($count) ? $count : NULL;
  }

}
