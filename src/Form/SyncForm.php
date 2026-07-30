<?php

declare(strict_types=1);

namespace Drupal\formulize\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\formulize\FormulizeBootstrap;
use Drupal\formulize\FormulizeUserSync;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Copies every existing Drupal user and role into Formulize in one pass.
 *
 * Only needed to catch up: from then on the entity hooks keep the two in step as users and roles are
 * created and changed.
 */
class SyncForm extends FormBase {

  public function __construct(
    protected readonly FormulizeUserSync $sync,
    protected readonly FormulizeBootstrap $bootstrap,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(FormulizeUserSync::class),
      $container->get(FormulizeBootstrap::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'formulize_sync';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->bootstrap->isConfigured()) {
      $form['unconfigured'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['error' => [$this->t('Formulize has not been located yet. Set its path or URL on the settings tab first.')]],
      ];
      return $form;
    }

    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p>Gives every Drupal user an account in Formulize, and every Drupal role a group, so that Drupal users arrive in Formulize with permissions that mean something. Run this once after connecting the two systems; afterwards, users and roles are kept in step automatically as they change.</p><p>Nothing is removed from Formulize, and accounts that are already linked are updated rather than duplicated. Passwords are never copied: people sign in through Drupal.</p>'),
    ];

    $form['counts'] = [
      '#type' => 'item',
      '#title' => $this->t('To be synchronised'),
      '#markup' => $this->t('@users active users and @roles roles.', [
        '@users' => $this->countActiveUsers(),
        '@roles' => count($this->entityTypeManager->getStorage('user_role')->loadMultiple()),
      ]),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Synchronise users and groups'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uids = $this->activeUserIds();

    $batch = (new BatchBuilder())
      ->setTitle($this->t('Synchronising with Formulize'))
      ->setInitMessage($this->t('Preparing to synchronise.'))
      ->setErrorMessage($this->t('Synchronising with Formulize failed. Check the site log.'))
      ->setFinishCallback([static::class, 'finished'])
      ->addOperation([static::class, 'syncGroups']);

    // In chunks, because a site with a large user table would otherwise exhaust the request.
    foreach (array_chunk($uids, 25) as $chunk) {
      $batch->addOperation([static::class, 'syncUsers'], [$chunk]);
    }

    batch_set($batch->toArray());
  }

  /**
   * Batch operation: map the built in roles, then create a group for every other role.
   */
  public static function syncGroups(array &$context): void {
    /** @var \Drupal\formulize\FormulizeUserSync $sync */
    $sync = \Drupal::service(FormulizeUserSync::class);
    $sync->seedMappings();

    $roles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple();
    foreach ($roles as $role) {
      if ($role instanceof RoleInterface) {
        $sync->createGroup($role);
      }
    }

    $context['results']['groups'] = count($roles);
    $context['message'] = (string) t('Synchronised @count roles.', ['@count' => count($roles)]);
  }

  /**
   * Batch operation: create or update the Formulize account for a chunk of users.
   */
  public static function syncUsers(array $uids, array &$context): void {
    /** @var \Drupal\formulize\FormulizeUserSync $sync */
    $sync = \Drupal::service(FormulizeUserSync::class);
    $storage = \Drupal::entityTypeManager()->getStorage('user');

    foreach ($storage->loadMultiple($uids) as $account) {
      if (!$account instanceof UserInterface) {
        continue;
      }
      // Pass the current roles as the previous set too: this adds the memberships the user should have
      // and removes none, which is the right behaviour for a catch-up run.
      $sync->createUser($account);
      $context['results']['users'] = ($context['results']['users'] ?? 0) + 1;
    }

    $context['message'] = (string) t('Synchronised @count users so far.', [
      '@count' => $context['results']['users'] ?? 0,
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError((string) t('Synchronising with Formulize did not finish. Check the site log.'));
      return;
    }
    \Drupal::messenger()->addStatus((string) t('Synchronised @roles roles and @users users with Formulize.', [
      '@roles' => $results['groups'] ?? 0,
      '@users' => $results['users'] ?? 0,
    ]));
  }

  /**
   * IDs of the active users that should exist in Formulize.
   */
  private function activeUserIds(): array {
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->sort('uid');
    return array_values(array_map('intval', $query->execute()));
  }

  /**
   * How many active users there are.
   */
  private function countActiveUsers(): int {
    return (int) $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->count()
      ->execute();
  }

}
