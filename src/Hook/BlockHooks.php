<?php

declare(strict_types=1);

namespace Drupal\formulize\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Keeps a screen block rendering in the main response rather than being streamed in later.
 */
class BlockHooks {

  /**
   * Implements hook_block_build_BASE_BLOCK_ID_alter() for the Formulize screen block.
   *
   * A screen can never be cached, and BigPipe turns any uncacheable block into a placeholder that is
   * streamed in after the document head has already been sent. The markup arrives, but the stylesheet
   * and scripts the screen needs are html_head attachments, and by then it is too late to add anything
   * to the head - so the screen turns up unstyled and its JavaScript fails. Opting out of
   * placeholdering keeps the block in the main render pass, where its attachments still reach the head.
   *
   * The cost is that the page waits for Formulize to render before sending anything, which is the same
   * trade the field formatter already makes.
   */
  #[Hook('block_build_formulize_screen_alter')]
  public function blockBuildAlter(array &$build, BlockPluginInterface $block): void {
    $build['#create_placeholder'] = FALSE;
  }

}
