<?php

namespace Ballen\Pirrot\Commands;

use Ballen\Clip\Traits\RecievesArgumentsTrait;
use Ballen\Clip\Interfaces\CommandInterface;
use Ballen\Clip\Utilities\ArgumentsParser;
use Ballen\GPIO\GPIO;
use Ballen\GPIO\Exceptions\GPIOException;

/**
 * Class VoiceCommand
 *
 * @package Ballen\Pirrot\Commands
 */
class VoiceCommand extends AudioCommand implements CommandInterface
{

    use RecievesArgumentsTrait;

    /**
     * The TX/RX mode for voice communications.
     *
     * @var string
     */
    private $mode;

    /**
     * Stores value of the COR recording state.
     *
     * @var bool
     */
    private $corRecording = false;

    /**
     * How long to wait before value on COR pin settles - in seconds.
     *
     * @var int
     */
    private $debounceTime = 0.25;

    /**
     * VoiceCommand constructor.
     * @param ArgumentsParser $argv
     * @throws GPIOException
     */
    public function __construct(ArgumentsParser $argv)
    {
        parent::__construct($argv);

        // Sets the transmit/receive mode
        $this->mode = ucwords($this->config->get('transmit_mode'));

        // Sets the default LED's and outputs
        $this->outputPtt->setValue(GPIO::LOW);
        $this->outputLedRx->setValue(GPIO::LOW);
        $this->outputLedTx->setValue(GPIO::LOW);
    }

    /**
     * Handle the command.
     * @return void
     */
    public function handle()
    {

        // Detect if the repeater is enabled/disabled...
        if (!$this->config->get('enabled', false)) {
            $this->writeln('Repeater disabled in the configuration file!');
            $this->exitWithSuccess();
        }

        // Detect and handle the current RX/TX mode...
        $modeHandler = "main{$this->mode}";
        if (method_exists($this, $modeHandler)) {
            return $this->{$modeHandler}();
        }
        $this->writeln("RX/TX mode ({$this->mode}) not supported!");
        $this->exitWithError();
    }

    /**
     * Main loop handler for VOX transmission operations.
     *
     * @throws GPIOException
     * @retun void
     */
    private function mainVox()
    {
        while (true) {
            system($this->audioService->audioRecordBin . ' -t ' . $this->config->get('record_device',
                    'alsa') . ' default ' . $this->basePath . '/storage/input/buffer.ogg -V0 silence 1 0.1 5% 1 1.0 5%');
            $this->storeRecording();
            $this->outputLedTx->setValue(GPIO::HIGH);
            $this->outputPtt->setValue(GPIO::HIGH);
            $this->audioService->play($this->basePath . '/storage/input/buffer.ogg');
            $this->sendCourtesyTone();
            $this->outputLedTx->setValue(GPIO::LOW);
            $this->outputPtt->setValue(GPIO::LOW);
        }
    }

    /**
     * Main loop handler for COR transmission operations.
     *
     * @throws \Ballen\GPIO\Exceptions\GPIOException
     * @retun void
     */
    private function mainCor()
    {
        while (true) {
            $this->processCorRecording();
        }
    }

    /**
     * If enabled, archives/stores the audio transmission.
     *
     * @return void
     */
    private function storeRecording()
    {
        if ($this->config->get('store_recordings')) {
            $date = date('YmdHis');
            system('cp ' . $this->basePath . '/storage/input/buffer.ogg ' . $this->basePath . '/storage/recordings/' . $date . '.ogg');
        }
    }

    /**
     * If enabled, plays the courtesy tone at the end of transmissions.
     *
     * @return void
     */
    private function sendCourtesyTone()
    {
        if ($this->config->get('courtesy_tone', false)) {
            $this->audioService->tone($this->config->get('courtesy_tone', 'Beep'));
        }
    }

    /**
     * Handles the COR recording logic
     * @return void
     * @throws \Ballen\GPIO\Exceptions\GPIOException
     */
    private function processCorRecording()
    {
        if (!$this->corRecording && ($this->inputCos->getValue() == GPIO::HIGH)) {
            $this->outputLedRx->setValue(GPIO::HIGH);
            $pid = system($this->audioService->audioRecordBin . ' -t ' . $this->config->get('record_device',
                    'alsa') . ' default ' . $this->basePath . '/storage/input/buffer.ogg > /dev/null & echo $!');
            $this->corRecording = true;
            $timeout = microtime(true) + $this->debounceTime;
            while (true) {
                if ($this->inputCos->getValue() == GPIO::HIGH) {
                    $timeout = microtime(true) + $this->debounceTime;
                }
                usleep(10000);
                if ($timeout < microtime(true)) {
                    system('kill ' . $pid);
                    $this->outputLedRx->setValue(GPIO::LOW);
                    $this->storeRecording();
                    $this->outputPtt->setValue(GPIO::HIGH);
                    $this->outputLedTx->setValue(GPIO::HIGH);
                    $this->audioService->play($this->basePath . '/storage/input/buffer.ogg');
                    $this->sendCourtesyTone();
                    $this->outputPtt->setValue(GPIO::LOW);
                    $this->outputLedTx->setValue(GPIO::LOW);
                    $this->corRecording = false;
                    break;
                }
            }
        }
        usleep(10000); // Sleep a tenth of a second...
    }

}