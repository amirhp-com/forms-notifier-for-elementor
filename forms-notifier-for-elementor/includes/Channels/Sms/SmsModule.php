<?php
/**
 * SMS module.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\Passthrough;
use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/** Registers the built-in SMS gateways and the help topics. */
final class SmsModule implements ModuleInterface {

	/** Attach hooks. */
	public function register(): void {
		add_filter( 'fnfe_sms_providers', [ self::class, 'providers' ], 5 );
		add_filter( 'fnfe_help_topics', [ SmsHelp::class, 'topics' ] );
	}

	/**
	 * Built-in gateways.
	 *
	 * @return array<string,string>
	 */
	public static function provider_classes(): array {
		return [
			'kavenegar'   => Iran\Kavenegar::class,
			'ippanel'     => Iran\IpPanel::class,
			'farazsms'    => Iran\FarazSms::class,
			'melipayamak' => Iran\MeliPayamak::class,
			'smsir'       => Iran\SmsIr::class,
			'ghasedak'    => Iran\Ghasedak::class,
			'mediana'     => Iran\Mediana::class,
			'amoot'       => Iran\Amoot::class,
			'limosms'     => Iran\LimoSms::class,
			'asanak'      => Iran\Asanak::class,
			'payamresan'  => Iran\PayamResan::class,
			'parsgreen'   => Iran\ParsGreen::class,
			'raygansms'   => Iran\RayganSms::class,
			'farazlegacy' => Iran\FarazLegacy::class,
			'twilio'      => International\Twilio::class,
			'vonage'      => International\Vonage::class,
			'plivo'       => International\Plivo::class,
			'bird'        => International\Bird::class,
			'clicksend'   => International\ClickSend::class,
			'infobip'     => International\Infobip::class,
			'telnyx'      => International\Telnyx::class,
			'brevo'       => International\Brevo::class,
			'smsto'       => International\SmsTo::class,
			'elks'        => International\FortySixElks::class,
			'smsapi'      => International\SmsApi::class,
			'msg91'       => International\Msg91::class,
			'netgsm'      => International\NetGsm::class,
			'messagebird' => International\MessageBird::class,
			'wpsms'       => Passthrough\WpSms::class,
			'pwsms'       => Passthrough\PersianWooSms::class,
			'custom'      => Passthrough\CustomHttp::class,
		];
	}

	/**
	 * Filter fnfe_sms_providers.
	 *
	 * @param array $providers Id => class or instance.
	 */
	public static function providers( $providers ): array {
		return (array) $providers + self::provider_classes();
	}
}
