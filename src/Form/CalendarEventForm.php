<?php

namespace Drupal\google_calendar_service\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\google_calendar_service\CalendarEditEvents;
use Drupal\google_calendar_service\Entity\Calendar;
use Google_Service_Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * Form controller for Google Calendar Event edit forms.
 *
 * @ingroup google_calendar_service
 */
class CalendarEventForm extends ContentEntityForm {

  /**
   * The edit event service.
   *
   * @var \Drupal\google_calendar_service\CalendarEditEvents
   */
  protected $editEvent;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Private tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempstore;

  /**
   * CalendarEventForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time interface.
   * @param \Drupal\google_calendar_service\CalendarEditEvents $editEvent
   *   The calendar edit events service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore
   *   The private store factory.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $bundle_info = NULL,
    TimeInterface $time = NULL,
    CalendarEditEvents $editEvent,
    MessengerInterface $messenger,
    PrivateTempStoreFactory $tempstore,
    EntityTypeManagerInterface $entity_type_manager) {

    parent::__construct($entity_repository, $bundle_info, $time);
    $this->editEvent = $editEvent;
    $this->messenger = $messenger;
    $this->tempstore = $tempstore;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('google_calendar_service.edit_events'),
      $container->get('messenger'),
      $container->get('tempstore.private'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $calendar = NULL) {
    // @var $entity \Drupal\google_calendar_service\Entity\GoogleCalendarEvent.
    $form = parent::buildForm($form, $form_state);
    // Get the event id.
    $buildInfo = $form_state->getBuildInfo();
    $entity = $buildInfo['callback_object'];
    $eventId = $entity->getEntity()->getGoogleEventId();
    $calendarId = $entity->getEntity()->get('calendar')->getValue() ?
      $entity->getEntity()->get('calendar')->getValue()[0]['target_id'] :
      $calendar;
    $form_state->set('event_id', $eventId);
    $form_state->set('calendar_id', $calendarId);
    $form['start_date']['#required'] = TRUE;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = &$this->entity;

    $status = parent::save($form, $form_state);
    $values = $form_state->getValues();

    $data['name'] = $values['name'] ? $values['name'][0]['value'] : NULL;
    $data['location'] = $values['location'] ?
      $values['location'][0]['value'] :
      NULL;
    $data['description'] = $values['description'] ?
      $values['description'][0]['value'] :
      NULL;

    $startDate = $values['start_date'][0]['value'];
    if (!empty($startDate)) {
      $data['startDate'] = $startDate->format('Y-m-d H:i');
    }

    $endDate = $values['end_date'][0]['value'];
    if (!empty($endDate)) {
      $data['endDate'] = $endDate->format('Y-m-d H:i');
    }

    // Get calendar id.
    $tempStore = $this->tempstore->get('google_calendar_service');
    $calendarId = $form_state->get('calendar_id') ?: $tempStore->get('calendarId');

    switch ($status) {
      case SAVED_NEW:
        $this->messenger->addMessage($this->t(
          'Created the %label Google Calendar Event.',
          [
            '%label' => $entity->label(),
          ]
        ));

        if (!empty($calendarId)) {
          $calendar = $this->getCalendarId($calendarId);
          // Get timezone.
          $timeZone = $this->editEvent->service->calendars->get($calendar)
            ->getTimeZone();

          if ($entity->id()) {
            $eventId = $this->generateRandomString();
            $entity->setGoogleEventId($eventId);

            $entity->set('calendar', $calendarId);
            $entity->save();
            $this->editEvent->addCalendarEvent(
              $calendar,
              $data['name'],
              $data['location'],
              $data['description'],
              $data['startDate'],
              $data['endDate'],
              $timeZone
            );
          }
          else {
            // In the unlikely case something went wrong on save, the node
            // will be rebuilt and node form redisplayed the same way as
            // in preview.
            $this->messenger->addMessage(
              $this->t('The post could not be saved.'),
              'error'
            );
            $form_state->setRebuild();
          }
        }
        break;

      default:
        // The event id.
        $eventId = $form_state->get('event_id');
        $calendarId = $this->getCalendarId($form_state->get('calendar_id'));

        // Get timezone.
        $timeZone = $this->editEvent->service->calendars->get($calendarId)
          ->getTimeZone();

        try {
          $this->editEvent->patchCalendar(
            $calendarId,
            $eventId,
            $data,
            $timeZone
          );
        }
        catch (Google_Service_Exception $e) {
          // Catch non-authorized exception.
          if ($e->getCode() == 401) {
            return FALSE;
          }
        }
        $this->messenger->addMessage($this->t(
          'Saved the %label Google Calendar Event.',
          [
            '%label' => $entity->label(),
          ]
        ));
    }
  }

  /**
   * Get google calendar id.
   *
   * @param string $calendarId
   *   The calendar id numeric.
   *
   * @return mixed
   *   Calendar id.
   */
  public function getCalendarId($calendarId) {
    $calendar = Calendar::load($calendarId);

    return $calendar->getGoogleCalendarId();
  }

  /**
   * Returns a random string.
   *
   * @param int $length
   *   The length of the random generated random string.
   *
   * @return string
   *   A random alphanumeric string.
   */
  public function generateRandomString($length = 26) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }

    return $randomString;
  }

}
