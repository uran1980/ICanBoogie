<?php

/*
 * This file is part of the ICanBoogie package.
 *
 * (c) Olivier Laviale <olivier.laviale@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ICanBoogie;

class Mailer
{
	const T_DESTINATION = 'destination';
	const T_FROM = 'from';
	const T_HEADER = 'header';
	const T_MESSAGE = 'message';
	const T_SUBJECT = 'subject';
	const T_TYPE = 'type';
	const T_BCC = 'bcc';

	public $charset = 'utf-8';
	public $destination = array();
	public $message;
	public $subject;
	public $type = 'plain';
	public $bcc = array();

	private $headers = array();

	public function __construct(array $tags)
	{
		foreach ($tags as $tag => $value)
		{
			switch ($tag)
			{
				case self::T_DESTINATION:
				{
					if (is_array($value))
					{
						foreach ($value as $name => $email)
						{
							$this->addDestination($email, is_numeric($name) ? null : $name);
						}

						break;
					}

					$this->addDestination($value, null);
				}
				break;

				case self::T_FROM:
				{
					$this->modifyHeader(array('From' => $value, 'Reply-To' => $value, 'Return-Path' => $value));
				}
				break;

				case self::T_HEADER:
				{
					$this->modifyHeader($value);
				}
				break;

				case self::T_MESSAGE:
				{
					$this->message = $value; break;
				}
				break;

				case self::T_SUBJECT:
				{
					$this->subject = $value;
				}
				break;

				case self::T_TYPE:
				{
					$this->type = $value;
				}
				break;

				case self::T_BCC:
				{
					if (!$value)
					{
						break;
					}

					$this->modifyHeader(array('Bcc' => is_string($value) ? $value : implode(',', (array) $value)));
				}
				break;
			}
		}
	}

	/**
	 * Adds a destination address ("To")
	 *
	 * @param $address
	 * @param $name
	 */

	public function addDestination($address, $name=null)
	{
		$this->destination[$address] = $name;
	}

	public function modifyHeader(array $modifiers)
	{
		$this->headers = $modifiers + $this->headers;
	}

	public function __invoke()
	{
		$subject = $this->subject;
		$message = $this->message;
		$type = $this->type;

		new Mailer\BeforeSendEvent($this, $subject, $message, $type);

		$to = $this->concat_addresses($this->destination);

		$parts = $this->headers + array
		(
			'Content-Type' => 'text/' . $type . '; charset=' . $this->charset
		);

		$header = null;

		foreach ($parts as $identifier => $value)
		{
			$header .= $identifier . ': ' . $value . "\r\n";
		}

		if (0)
		{
			log
			(
				'<pre>mail("!to", "!subject", "!message", "!header")</pre>', array
				(
					'!to' => $to,
					'!subject' => $subject,
					'!message' => $message,
					'!header' => str_replace("\r\n", '\r\n', $header)
				)
			);
		}

		return mail($to, $subject, $message, $header);
	}

	private function concat_addresses($addresses)
	{
		$rc = array();

		foreach ($this->destination as $address => $name)
		{
			$rc[] = $name ? $name . ' <' . $address . '>' : $address;
		}

		return implode(',', $rc);
	}
}

namespace ICanBoogie\Mailer;

/**
 * Event class for the `ICanBoogie\Mailer::send:before` event.
 */
class BeforeSendEvent extends \ICanBoogie\Event
{
	/**
	 * Reference to the subject.
	 *
	 * @var string
	 */
	public $subject;

	/**
	 * Reference to the message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Reference to the type.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * The event is constructed with the type `send:before`.
	 *
	 * @param \ICanBoogie\Mailer $target
	 * @param string $subject
	 * @param string $message
	 * @param string $type
	 */
	public function __construct(\ICanBoogie\Mailer $target, &$subject, &$message, &$type)
	{
		$this->subject = &$subject;
		$this->message = &$message;
		$this->type = &$type;

		parent::__construct($target, 'send:before');
	}
}