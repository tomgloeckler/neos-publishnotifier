<?php

declare(strict_types=1);

namespace CodeQ\PublishNotifier;

use Maknz\Slack\Client;
use Neos\Flow\Annotations as Flow;
use Neos\SymfonyMailer\Service\MailerService;
use Psr\Log\LoggerInterface;
use Neos\Flow\Configuration\Exception\InvalidConfigurationException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\PublishingServiceInterface;
use Neos\Neos\Domain\Service\UserService;
use Symfony\Component\Mime\Part\TextPart;

/**
 * @Flow\Scope("singleton")
 */
class Notifier
{
	#[Flow\Inject]
	protected MailerService $mailerService;

	#[Flow\Inject]
    protected LoggerInterface $systemLogger;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PublishingServiceInterface $publishingService;

	#[Flow\InjectConfiguration(path: 'http.baseUri', package: 'Neos.Flow')]
    protected string $baseUri;

    protected array $settings;

    protected bool $notificationHasBeenSentInCurrentInstance = false;

    public function injectSettings(array $settings): void {
        $this->settings = $settings;
    }

    /**
     * Send out emails for a change in a workspace.
     *
     * @throws InvalidConfigurationException|\Symfony\Component\Mailer\Exception\TransportExceptionInterface
	 */
    protected function sendEmails(Workspace $targetWorkspace): void
    {
        if(!$this->settings['email']['enabled']) {
            return;
        }
        if(!$this->settings['email']['senderAddress']) {
            throw new InvalidConfigurationException('The CodeQ.PublishNotifier email.senderAddress configuration does not exist.');
        }
        if(!$this->settings['email']['notifyEmails']) {
            throw new InvalidConfigurationException('The CodeQ.PublishNotifier email.notifyEmails configuration does not exist.');
        }

        $currentUser = $this->userService->getCurrentUser();
        $currentUserName = $currentUser->getLabel();
        $targetWorkspaceName = $targetWorkspace->getTitle();
        $reviewUrl = sprintf('%1$sneos/management/workspaces/show?moduleArguments[workspace][__identity]=%2$s', $this->baseUri, $targetWorkspace->getName());

        $senderAddress = $this->settings['email']['senderAddress'];
        $senderName = $this->settings['email']['senderName'];
        $subject = sprintf($this->settings['email']['subject'], $currentUserName);
        $body = sprintf($this->settings['email']['body'], $currentUserName, $targetWorkspaceName, $reviewUrl);
		$body = new TextPart($body, 'utf-8', 'plain');

        foreach ($this->settings['email']['notifyEmails'] as $email) {
            try {
                $mail = new Email();
                $mail
                    ->from(new Address($senderAddress, $senderName))
                    ->to(new Address($email))
                    ->subject($subject);
                $mail->setBody($body);
                $this->mailerService->getMailer()->send($mail);
            } catch (\Exception $exception) {
                $this->systemLogger->error($exception);
            }
        }
    }

    /**
     * Send out a slack message for a change in a workspace.
     *
     * @throws InvalidConfigurationException
     */
    protected function sendSlackMessages(Workspace $targetWorkspace): void
    {
        if(!$this->settings['slack']['enabled']) {
            return;
        }
        if(empty($this->settings['slack']['postTo'])) {
            throw new InvalidConfigurationException('The CodeQ.PublishNotifier slack.postTo configuration expects at least one target if enabled.');
        }

        $currentUser = $this->userService->getCurrentUser();
        $currentUserName = $currentUser->getLabel();
        $targetWorkspaceName = $targetWorkspace->getTitle();
        $reviewUrl = sprintf('%1$s/neos/management/workspaces/show?moduleArguments[workspace][__identity]=%2$s', $this->baseUri, $targetWorkspace->getName());

        $message = sprintf($this->settings['slack']['message'], $currentUserName, $targetWorkspaceName, $reviewUrl);

        foreach ($this->settings['slack']['postTo'] as $postToKey => $postTo) {
            if (empty($postTo['webhookUrl'])) {
                throw new InvalidConfigurationException('The CodeQ.PublishNotifier slack.postTo ' . $postToKey . ' requires a webhookUrl.');
            }

            $clientSetting = isset($postTo['clientSettings']) ? $postTo['clientSettings'] : [];
            $client = new Client($postTo['webhookUrl'], $clientSetting);
            $slackMessage = $client->createMessage();
            $slackMessage->send($message);
        }
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return void
     * @throws InvalidConfigurationException
     */
    public function notify($node, $targetWorkspace)
    {
        // skip sending another notification if more than one node is to be published
        if ($this->notificationHasBeenSentInCurrentInstance) {
            return;
        }

        // skip changes to personal workspace
        if ($targetWorkspace->isPersonalWorkspace()) {
            return;
        }

        // skip changes to public/live workspace
        if ($targetWorkspace->isPublicWorkspace() && !$this->settings['notify']['publicWorkspace']) {
            return;
        }

        if($targetWorkspace->isInternalWorkspace()) {
            $isFirstChangeInWorkspace = !$this->publishingService->getUnpublishedNodes($targetWorkspace);

            if ($isFirstChangeInWorkspace && !$this->settings['notify']['internalWorkspace']['onFirstChange']) {
                return;
            }

            if (!$isFirstChangeInWorkspace && !$this->settings['notify']['internalWorkspace']['onAdditionalChange']) {
                return;
            }
        }

        $this->sendEmails($targetWorkspace);
        $this->sendSlackMessages($targetWorkspace);
        $this->notificationHasBeenSentInCurrentInstance = true;
    }
}
