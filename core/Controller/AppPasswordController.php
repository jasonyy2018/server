<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Core\Controller;

use BadMethodCallException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Settings\Activity\Provider;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Authentication\Exceptions\CredentialsUnavailableException;
use OCP\Authentication\Exceptions\PasswordUnavailableException;
use OCP\Authentication\LoginCredentials\IStore;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;

class AppPasswordController extends \OCP\AppFramework\OCSController {

	/** @var ISession */
	private $session;

	/** @var ISecureRandom */
	private $random;

	/** @var IProvider */
	private $tokenProvider;

	/** @var IActivityManager */
	private $activityManager;

	/** @var ILogger */
	private $logger;

	/** @var IStore */
	private $credentialStore;

	public function __construct(string $appName,
								IRequest $request,
								ISession $session,
								ISecureRandom $random,
								IProvider $tokenProvider,
								IStore $credentialStore,
								IActivityManager $activityManager,
								ILogger $logger) {
		parent::__construct($appName, $request);

		$this->session = $session;
		$this->random = $random;
		$this->tokenProvider = $tokenProvider;
		$this->credentialStore = $credentialStore;
		$this->activityManager = $activityManager;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 * @throws OCSForbiddenException
	 */
	public function getAppPassword(): DataResponse {
		// We do not allow the creation of new tokens if this is an app password
		if ($this->session->exists('app_password')) {
			throw new OCSForbiddenException('You cannot request an new apppassword with an apppassword');
		}

		try {
			$credentials = $this->credentialStore->getLoginCredentials();
		} catch (CredentialsUnavailableException $e) {
			throw new OCSForbiddenException();
		}

		try {
			$password = $credentials->getPassword();
		} catch (PasswordUnavailableException $e) {
			$password = null;
		}

		$userAgent = $this->request->getHeader('USER_AGENT');

		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);

		$generatedToken = $this->tokenProvider->generateToken(
			$token,
			$credentials->getUID(),
			$credentials->getLoginName(),
			$password,
			$userAgent,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);

		$event = $this->activityManager->generateEvent();
		$event->setApp('settings')
			->setType('security')
			->setAffectedUser($credentials->getUID())
			->setAuthor($credentials->getUID())
			->setSubject(Provider::APP_TOKEN_CREATED, ['name' => $generatedToken->getName()])
			->setObject('app_token', $generatedToken->getId(), 'App Password');

		try {
			$this->activityManager->publish($event);
		} catch (BadMethodCallException $e) {
			$this->logger->warning('could not publish activity');
			$this->logger->logException($e);
		}

		return new DataResponse([
			'apppassword' => $token
		]);
	}
}
