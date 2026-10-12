<?php

namespace moqo\watchdog\controllers;

use Craft;
use craft\web\Controller;
use moqo\watchdog\Plugin;
use moqo\watchdog\services\Pairing;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Control panel side of pairing: an admin approves the request the Watchdog app sent them here with.
 */
class PairController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        // Admins only, but also on production where allowAdminChanges is off: nothing here touches project config.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        $challenge = $this->request->getQueryParam('challenge');
        if (!Pairing::isChallenge($challenge)) {
            throw new BadRequestHttpException('Deze koppellink is onvolledig. Klik in Watchdog opnieuw op Verbinden.');
        }

        return $this->renderTemplate('watchdog/pair', [
            'challenge' => strtolower($challenge),
            'code' => Pairing::code($challenge),
            'label' => mb_substr((string)$this->request->getQueryParam('label', ''), 0, 120),
            'ready' => Plugin::getInstance()->pairing->tableExists(),
            'done' => false,
        ]);
    }

    public function actionApprove(): Response
    {
        $this->requirePostRequest();

        $challenge = $this->request->getBodyParam('challenge');
        if (!Pairing::isChallenge($challenge)) {
            throw new BadRequestHttpException('Ongeldige koppelaanvraag.');
        }
        $label = mb_substr((string)$this->request->getBodyParam('label', ''), 0, 120);
        Plugin::getInstance()->pairing->approve($challenge, $label, Craft::$app->getUser()->getId());

        return $this->renderTemplate('watchdog/pair', [
            'challenge' => strtolower($challenge),
            'code' => Pairing::code($challenge),
            'label' => $label,
            'ready' => true,
            'done' => true,
        ]);
    }

    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        Plugin::getInstance()->pairing->revoke((int)$this->request->getRequiredBodyParam('id'));
        $this->setSuccessFlash('Koppeling ingetrokken.');

        return $this->redirectToPostedUrl(null, 'settings/plugins/watchdog');
    }
}
