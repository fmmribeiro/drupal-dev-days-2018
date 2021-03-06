<?php

namespace Drupal\ddd_attendee\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ddd_attendee\Event\TitoEvents;
use Drupal\ddd_attendee\Exception\TitoWebhookException;
use Drupal\ddd_attendee\Event\TitoEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class TitoWebhookController.
 */
class TitoWebhookController extends ControllerBase {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * TitoWebhookController constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The Switch event dispatcher.
   */
  public function __construct(EventDispatcherInterface $event_dispatcher) {
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('event_dispatcher'),
      $container->get('lock')
    );
  }

  /**
   * Process a payload sent from ti.to webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response code.
   */
  public function processPayload(Request $request) {
    try {
      /*
       * Validate HMAC if the webhook_security_token is set.
       *
       * Check https://ti.to/docs/webhook
       */
      if (!empty($this->config('ddd_attendee.settings')->get('webhook_security_token'))) {
        /*
         * Get tito signature from header.
         */
        $signature = $request->headers->get("Tito-Signature");
        /*
         * If empty signature.
         */
        if (empty($signature)) {
          throw new TitoWebhookException("Empty signature.");
        }
        /*
         * Hash payload to check if is the same as signature
         */
        $hash = base64_encode(hash_hmac("sha256", $request->getContent(), $this->config('ddd_attendee.settings')->get('webhook_security_token'), TRUE));
        /*
         * If signature hash is not equal to generate hash.
         */
        if ($signature != $hash) {
          throw new TitoWebhookException("Signature mismatch.");
        }
      }
      /*
       * Check if request is not empty.
       */
      if (empty($request->getContent())) {
        throw new TitoWebhookException("Request is empty.");
      }
      /*
       * Decode request.
       */
      $payload = Json::decode($request->getContent());
      /*
       * Check if request was decoded.
       */
      if (empty($payload)) {
        throw new TitoWebhookException("Could not decode payload.");
      }
      /*
       * Create a ti.to event.
       */
      $tito_event = new TitoEvent(TitoEvents::WEBHOOK_CALLBACK, $payload);
      /*
       * Dispatch
       */
      $this->eventDispatcher->dispatch(TitoEvents::WEBHOOK_CALLBACK, $tito_event);
    }
    /*
     * Catch ti.to webhook exceptions.
     */
    catch (TitoWebhookException $ex) {
      return new Response('', 400);
    }
    return new Response('', 200);
  }

}
