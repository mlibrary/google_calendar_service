<?php

namespace Drupal\google_calendar_service\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\google_calendar_service\CalendarEditEvents;
use Drupal\google_calendar_service\Entity\Calendar;

/**
 * Validates the TimeAvailable constraint.
 */
class TimeAvailableConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * @var \Symfony\Component\Validator\Context\ExecutionContextInterface
   */
  protected $context;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Google Calendar Edit Events definition.
   *
   * @var \Drupal\google_calendar_service\CalendarEditEvents
   */
  public $EditEventService;

  /**
   * Creates a new TimeAvailableConstraintValidator instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \CalendarEditEvents $route_match
   *   The edit events service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, CalendarEditEvents $edit_events) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->EditEventService = $edit_events;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('google_calendar_service.edit_events')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint) {
    if ($entity->hasField('start_date') && $entity->hasField('end_date') && $entity->hasField('calendar')) {
      $calendar_id = $entity->get('calendar')->target_id ?? $this->routeMatch->getParameter('calendar');
      $end_date = date('c', $entity->get('end_date')->value);
      $start_date = date('c', $entity->get('start_date')->value);
      $calendar = $this->entityTypeManager->getStorage('gcs_calendar')->load($calendar_id);
      if ($calendar instanceof Calendar && $calendar->get('validate_date')->value == TRUE) {
        $calendarId = $calendar->get('calendar_id')->value;
        $event_id = $entity->get('event_id')->value ?? NULL;
        if (!$this->EditEventService->verifyTimeGoogleCalendar($calendarId, $start_date, $end_date, $event_id)) {
          $this->context->buildViolation($constraint->notAvailable, ['%start' => $start_date])
            ->atPath('start_date')
            ->addViolation();
          $this->context->buildViolation($constraint->notAvailable, ['%end' => $end_date])
            ->atPath('end_date')
            ->addViolation();
        }
      }
    }

    return NULL;
  }

}
