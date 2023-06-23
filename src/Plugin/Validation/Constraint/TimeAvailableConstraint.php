<?php

namespace Drupal\google_calendar_service\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\CompositeConstraintBase;

/**
 * Checks that the submitted dates are available in the calendar.
 *
 * @Constraint(
 *   id = "TimeAvailable",
 *   label = @Translation("Time Available", context = "Validation"),
 *   type = "entity:gcs_calendar_event"
 * )
 */
class TimeAvailableConstraint extends CompositeConstraintBase {

  // The message that will be shown if the time is not available.
  public $notAvailable = 'The event times are already taken. Please select a different calendar, day or time.';

  /**
   * {@inheritdoc}
   */
  public function coversFields() {
    return ['start_date', 'end_date'];
  }

}
