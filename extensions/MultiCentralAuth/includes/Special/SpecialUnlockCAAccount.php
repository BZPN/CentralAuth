<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

class SpecialUnlockCAAccount extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserNameUtils $userNameUtils;
	private UserFactory $userFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserNameUtils $userNameUtils,
		UserFactory $userFactory
	) {
		parent::__construct( 'UnlockCAAccount', 'ca-lock' );
		$this->dbProvider = $dbProvider;
		$this->userNameUtils = $userNameUtils;
		$this->userFactory = $userFactory;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [
			'oojs-ui-core.styles',
			'oojs-ui-widgets.styles',
			'mediawiki.widgets.styles',
			'ext.multicentralauth.styles'
		] );

		$target = $this->getRequest()->getText( 'target', $subpage );

		$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'mca-unlock-intro' )->text() ) );

		$formDescriptor = [
			'target' => [
				'type' => 'user',
				'name' => 'target',
				'label-message' => 'mca-target-label',
				'required' => true,
				'default' => $target,
			],
			'reason' => [
				'type' => 'text',
				'name' => 'reason',
				'label-message' => 'mca-comment',
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'unlockcaaccount' );
		$htmlForm->setSubmitProgressive( true );
		$htmlForm->prepareForm();

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( false ), 'unlockcaaccount', 'mca-header-type-view' ) );
	}

	public function onSubmit( array $formData ) {
		$targetName = $formData['target'];
		$reason = $formData['reason'];

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$userRow = $dbw->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->where( [ 'user_name' => $targetName ] )
			->fetchRow();

		if ( !$userRow ) {
			return [ 'mca-error-user-not-found' ];
		}

		$dbw->delete(
			'mca_locks',
			[ 'mcl_user_id' => $userRow->user_id ],
			__METHOD__
		);

		// Log action
		$targetUser = $this->userFactory->newFromName( $targetName );
		if ( $targetUser ) {
			$logEntry = new \ManualLogEntry( 'mca-lock-log', 'unlock' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $targetUser->getUserPage() );
			$logEntry->setComment( $reason );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-unlock-success', $targetName )->parse() ) );
		return true;
	}

	private function getFramedFieldsetLayout( $html, $legendMsg, $headerClass = '' ): string {
		if ( is_array( $legendMsg ) ) {
			$label = $this->msg( ...$legendMsg )->text();
		} else {
			$label = $this->msg( $legendMsg )->text();
		}

		return Html::rawElement( 'div', [ 'class' => 'mw-htmlform-ooui-wrapper oo-ui-panelLayout-framed oo-ui-panelLayout-padded', 'style' => 'margin-bottom: 1em;' ],
			Html::rawElement( 'h2', [ 'class' => 'mca-box-header ' . $headerClass ], $label ) .
			Html::rawElement( 'div', [ 'class' => 'oo-ui-fieldsetLayout-group' ], $html )
		);
	}
}
