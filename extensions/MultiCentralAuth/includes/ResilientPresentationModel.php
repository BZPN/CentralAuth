<?php

namespace MediaWiki\Extension\MultiCentralAuth;

if ( class_exists( '\MediaWiki\Extension\Echo\PresentationModel\EchoEventPresentationModel' ) ) {
	abstract class ResilientPresentationModel extends \MediaWiki\Extension\Echo\PresentationModel\EchoEventPresentationModel {}
} else {
	abstract class ResilientPresentationModel extends \EchoEventPresentationModel {}
}
