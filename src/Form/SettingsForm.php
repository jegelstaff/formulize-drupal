<?php

declare(strict_types=1);

namespace Drupal\formulize\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\formulize\FormulizePathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tells the module where Formulize is, and how much of its front end to bring along.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The path resolver.
   */
  protected FormulizePathResolver $pathResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->pathResolver = $container->get(FormulizePathResolver::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'formulize_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['formulize.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('formulize.settings');
    $location = (string) $config->get('location');

    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Formulize location'),
      '#default_value' => $location,
      '#required' => TRUE,
      '#maxlength' => 512,
      '#description' => $this->t('Either the address Formulize is served at, such as <code>@url</code>, or the filesystem path to its directory, such as <code>@path</code>. The address is usually easier, and works as long as Formulize and Drupal share a document root on this server. Use the filesystem path if the address cannot be worked out.', [
        '@url' => 'http://example.com/formulize',
        '@path' => '/var/www/html',
      ]),
    ];

    // Show what the current setting actually resolves to, so an administrator can see it working
    // rather than having to save and then hunt for a screen that failed to appear.
    if ($location !== '') {
      $result = $this->pathResolver->resolve($location);
      if ($result['path'] !== NULL) {
        $form['status'] = [
          '#theme' => 'status_messages',
          '#message_list' => ['status' => [$this->pathResolver->describeStrategy($result['strategy'], $result['path'])]],
          '#weight' => -10,
        ];
      }
      else {
        $form['status'] = [
          '#theme' => 'status_messages',
          '#message_list' => ['error' => array_merge(
            [$this->t('Formulize could not be found from the current setting.')],
            $result['tried'],
          )],
          '#weight' => -10,
        ];
      }
    }

    $form['front_end'] = [
      '#type' => 'details',
      '#title' => $this->t('Front end'),
      '#open' => FALSE,
      '#description' => $this->t("A Formulize screen brings its own stylesheet and scripts. They are taken from Formulize itself as it renders, so the versions always match the rest of Formulize rather than being pinned here."),
    ];

    $form['front_end']['attach_js_assets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Load the scripts Formulize asks for, including jQuery'),
      '#default_value' => (bool) $config->get('attach_js_assets'),
      '#description' => $this->t('Formulize screens need jQuery and jQuery UI, which Drupal 11 does not provide. Turn this off only if your theme already loads versions Formulize can work with, since whichever loads last wins.'),
    ];

    $form['front_end']['load_theme_fonts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Load the Formulize theme webfont and icon font'),
      '#default_value' => (bool) $config->get('load_theme_fonts'),
      '#description' => $this->t('Fetches the Poppins webfont and Font Awesome from their public CDNs, as a standalone Formulize page does. Turn this off if you would rather not make third party requests; some icons and lettering will differ.'),
    ];

    $form['session'] = [
      '#type' => 'details',
      '#title' => $this->t('Single sign-on'),
      '#open' => FALSE,
    ];

    $form['session']['authoritative_session'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Drupal decides who is signed in'),
      '#default_value' => (bool) $config->get('authoritative_session'),
      '#description' => $this->t('With this on, a visitor who is not logged into Drupal is signed out of Formulize as well, which is what single sign-on normally means. Turn it off to leave a session created by logging into Formulize directly alone, which is more convenient while developing with both systems open at once.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $location = trim((string) $form_state->getValue('location'));
    $result = $this->pathResolver->resolve($location);

    if ($result['path'] === NULL) {
      $message = $this->t('Formulize could not be found from this value.');
      foreach ($result['tried'] as $note) {
        $message = $this->t('@message @note', ['@message' => $message, '@note' => $note]);
      }
      $form_state->setErrorByName('location', $message);
      return;
    }

    // Stash it so submitForm can report which approach worked without resolving a second time.
    $form_state->set('formulize_resolved', $result);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('formulize.settings')
      ->set('location', trim((string) $form_state->getValue('location')))
      ->set('attach_js_assets', (bool) $form_state->getValue('attach_js_assets'))
      ->set('load_theme_fonts', (bool) $form_state->getValue('load_theme_fonts'))
      ->set('authoritative_session', (bool) $form_state->getValue('authoritative_session'))
      ->save();

    // The resolved path is cached, so drop it in case the location changed.
    $this->pathResolver->clearCache();

    $resolved = $form_state->get('formulize_resolved');
    if (is_array($resolved) && $resolved['path'] !== NULL) {
      $this->messenger()->addStatus($this->pathResolver->describeStrategy($resolved['strategy'], $resolved['path']));
    }

    parent::submitForm($form, $form_state);
  }

}
