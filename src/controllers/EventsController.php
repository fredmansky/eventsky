<?php
/**
 * @link https://fredmansky.at/
 * @copyright Copyright (c) Fredmansky GmbH
 */

namespace fredmansky\eventsky\controllers;

use Craft;

use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use fredmansky\eventsky\elements\Event;
use fredmansky\eventsky\Eventsky;
use fredmansky\eventsky\web\assets\editevent\EditEventAsset;
use craft\helpers\UrlHelper;
use craft\web\Controller;

use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The EventTypesController class is a controller that handles various event type related tasks such as
 * displaying, saving, deleting and reordering them in the control panel.
 * Note that all actions in this controller require administrator access in order to execute.
 *
 * @author Fredmansky
 * @since 3.0
 */
class EventsController extends Controller
{

    public function init()
    {
        $this->requireAdmin();
        parent::init();
    }

    public function actionIndex(array $variables = []): Response
    {
        $data = [
            'eventTypes' => Eventsky::$plugin->eventType->getAllEventTypes(),
        ];
        return $this->renderTemplate('eventsky/events/index', $data);
    }

    public function actionEdit(int $eventId = null, string $site = null): Response
    {
        $data = [];

        $eventTypes = Eventsky::$plugin->eventType->getAllEventTypes();

        $this->getView()->registerAssetBundle(EditEventAsset::class);

        /** @var Event $event */
        $event = null;

        $site = $this->getSiteForNewEvent($site);

        if ($eventId !== null) {
            $event = Eventsky::$plugin->event->getEventById($eventId);

            if (!$event) {
                throw new NotFoundHttpException(Craft::t('eventsky', 'translate.event.notFound'));
            }

            $eventType = $event->getType();
            $data['title'] = trim($event->title) ?: Craft::t('eventsky', 'translate.event.edit');
        } else {
            $request = Craft::$app->getRequest();
            $event = new Event();
            $eventType = $eventTypes[0];
            $event->siteId = $site->id;
            $event->typeId = $request->getQueryParam('typeId', $eventType->id);
            $event->slug = ElementHelper::tempSlug();

            // TODO: implement (SS)
            $event->enabled = true;
            $event->enabledForSite = true;

            $event->setFieldValuesFromRequest('fields');
            $data['title'] = Craft::t('eventsky', 'translate.event.new');
        }

        $data['eventId'] = $eventId;
        $data['eventType'] = $eventType;

        $data['eventTypeOptions'] = array_map(function($eventType) {
            return [
                'label' => $eventType->name,
                'value' => $eventType->id,
            ];
        }, $eventTypes);

        $data['event'] = $event;
        $data['element'] = $event;
        $data['site'] = $site;

        $data['crumbs'] = [
            [
                'label' => Craft::t('eventsky', 'translate.events.cpTitle'),
                'url' => UrlHelper::url('eventsky/events')
            ],
        ];

        $data['saveShortcutRedirect'] = 'eventsky/events'; // TODO: correct URL here (SS)
        $data['redirectUrl'] = 'eventsky/events';
        $data['shareUrl'] = '/admin/eventsky'; // TODO: implement
        $data['saveSourceAction'] = 'entries/save-entry';
        $data['isMultiSiteElement'] = Craft::$app->isMultiSite && count(Craft::$app->getSites()->allSiteIds) > 1;
        $data['canUpdateSource'] = true;

        $data['tabs'] = [
            [
                'label' => Craft::t('eventsky', 'translate.events.tab.eventData'),
                'url' => '#' . StringHelper::camelCase('tab' . Craft::t('eventsky', 'translate.events.tab.eventData')),
//                'class' => $hasErrors ? 'error' : null,
            ],
            [
                'label' => Craft::t('eventsky', 'translate.events.tab.tickets'),
                'url' => '#' . StringHelper::camelCase('tab' . Craft::t('eventsky', 'translate.events.tab.tickets')),
//                'class' => $hasErrors ? 'error' : null,
            ],
        ];

        foreach ($eventType->getFieldLayout()->getTabs() as $index => $tab) {
            // Do any of the fields on this tab have errors?
//            $hasErrors = false;
//            if ($event->hasErrors()) {
//                foreach ($tab->getFields() as $field) {
//                    /** @var Field $field */
//                    if ($hasErrors = $event->hasErrors($field->handle . '.*')) {
//                        break;
//                    }
//                }
//            }
            $hasErrors = null;

            $data['tabs'][] = [
                'label' => $tab->name,
                'url' => '#' . StringHelper::camelCase('tab' . $tab->name),
                'class' => $hasErrors ? 'error' : null,
            ];
        }

        return $this->renderTemplate('eventsky/events/edit', $data);
    }

    public function actionSwitchEntryType(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $event = $this->getEventModel();
        $this->populateEventModel($event);

        $data = [];
        $data['event'] = $event;
//
//        if (($response = $this->_prepEditEntryVariables($variables)) !== null) {
//            return $response;
//        }

        $view = $this->getView();
        $tabsHtml = !empty($variables['tabs']) ? $view->renderTemplate('_includes/tabs', $data) : null;
//        $fieldsHtml = $view->renderTemplate('entries/_fields', $variables);
//        $headHtml = $view->getHeadHtml();
//        $bodyHtml = $view->getBodyHtml();
//
        return $this->asJson(compact(
            'tabsHtml'
        ));
//            'fieldsHtml',
//            'headHtml',
//            'bodyHtml'
    }

    public function actionSave()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $eventId = $request->getBodyParam('eventId');

        if ($eventId) {
            $event = Eventsky::$plugin->event->getEventById($eventId);

            if (!$event) {
                throw new HttpException(404, Craft::t('eventsky', 'translate.event.notFound'));
            }
        } else {
            $event = new Event();
        }

        $event->title = $request->getBodyParam('title');
        $event->slug = $request->getBodyParam('slug');
        $event->typeId = $request->getBodyParam('typeId');
        $event->needsRegistration = $request->getBodyParam('needsRegistration');
        $event->registrationEnabled = $request->getBodyParam('registrationEnabled');
        $event->totalTickets = $request->getBodyParam('totalTickets');
        $event->hasWaitingList = $request->getBodyParam('hasWaitingList');
        $event->waitingListSize = $request->getBodyParam('waitingListSize');

        // save values from custom fields to event
        $event->setFieldValuesFromRequest('fields');

        if (($postDate = $request->getBodyParam('postDate')) !== null) {
            $event->postDate = DateTimeHelper::toDateTime($postDate) ?: null;
        }
        if (($expiryDate = $request->getBodyParam('expiryDate')) !== null) {
            $event->expiryDate = DateTimeHelper::toDateTime($expiryDate) ?: null;
        }
        if (($startDate = $request->getBodyParam('startDate')) !== null) {
            $event->startDate = DateTimeHelper::toDateTime($startDate) ?: null;
        }
        if (($endDate = $request->getBodyParam('endDate')) !== null) {
            $event->endDate = DateTimeHelper::toDateTime($endDate) ?: null;
        }

        if (!Craft::$app->getElements()->saveElement($event)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $event->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('eventsky', 'translate.event.notSaved'));

            // Send the event back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'event' => $event,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('eventsky', 'translate.event.saved'));

        return $this->redirectToPostedUrl($event);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $eventId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Eventsky::$plugin->event->deleteEventById($eventId);

        return $this->asJson(['success' => true]);
    }

    private function getSiteForNewEvent($site) {
        $sitesService = Craft::$app->getSites();
        $siteIds = $sitesService->allSiteIds;
        if ($site !== null) {
            $siteHandle = $site;
            $site = $sitesService->getSiteByHandle($siteHandle);
            if (!$site) {
                throw new BadRequestHttpException('Invalid site handle: ' . $siteHandle);
            }
        }

        // If there's only one site, go with that
        if ($site === null && count($siteIds) === 1) {
            $site = $sitesService->getSiteById($siteIds[0]);
        }

        // If we still don't know the site, give the user a chance to pick one
        if ($site === null) {
            return $this->renderTemplate('_special/sitepicker', [
                'siteIds' => $siteIds,
                'baseUrl' => "entries/event/new",
            ]);
        }

        return $site;
    }

    private function getEventModel(): Event
    {
        $request = Craft::$app->getRequest();
        $eventId = $request->getBodyParam('eventId');
        $siteId = $request->getBodyParam('siteId');

        if ($eventId) {
            $event = Eventsky::$plugin->event->getEventById($eventId);

            if (!$event) {
                throw new NotFoundHttpException('Event not found');
            }
        } else {
            $event = new Event();

            if ($siteId) {
                $event->siteId = $siteId;
            }
        }

        return $event;
    }

    private function populateEventModel(Event $event)
    {
        $request = Craft::$app->getRequest();

        // Set the entry attributes, defaulting to the existing values for whatever is missing from the post data
        $entry->typeId = $request->getBodyParam('typeId', $entry->typeId);
        $entry->slug = $request->getBodyParam('slug', $entry->slug);
        if (($postDate = $request->getBodyParam('postDate')) !== null) {
            $entry->postDate = DateTimeHelper::toDateTime($postDate) ?: null;
        }
        if (($expiryDate = $request->getBodyParam('expiryDate')) !== null) {
            $entry->expiryDate = DateTimeHelper::toDateTime($expiryDate) ?: null;
        }
        $entry->enabled = (bool)$request->getBodyParam('enabled', $entry->enabled);
        $entry->enabledForSite = (bool)$request->getBodyParam('enabledForSite', $entry->enabledForSite);
        $entry->title = $request->getBodyParam('title', $entry->title);

        if (!$entry->typeId) {
            // Default to the section's first entry type
            $entry->typeId = $entry->getSection()->getEntryTypes()[0]->id;
        }

        // Prevent the last entry type's field layout from being used
        $entry->fieldLayoutId = null;

        $fieldsLocation = $request->getParam('fieldsLocation', 'fields');
        $entry->setFieldValuesFromRequest($fieldsLocation);

        // Author
        $authorId = $request->getBodyParam('author', ($entry->authorId ?: Craft::$app->getUser()->getIdentity()->id));

        if (is_array($authorId)) {
            $authorId = $authorId[0] ?? null;
        }

        $entry->authorId = $authorId;

        // Parent
        if (($parentId = $request->getBodyParam('parentId')) !== null) {
            if (is_array($parentId)) {
                $parentId = reset($parentId) ?: '';
            }

            $entry->newParentId = $parentId ?: '';
        }

        // Revision notes
        $entry->setRevisionNotes($request->getBodyParam('revisionNotes'));
    }
}