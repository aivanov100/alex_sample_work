<?php

namespace behat\features\bootstrap;

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Testwork\Tester\Result\TestResult;
use const PHP_EOL;

/**
 * Defines application failure handling from the specific context.
 */
class FailureContext extends BaseContext
{

    /**
     * For failed scenarios, save the page html.
     *
     * @AfterScenario
     */
    public function afterFailedScenario(AfterScenarioScope $scope): void
    {
        $isStepFailed = $scope->getTestResult()->getResultCode() === TestResult::FAILED;
        if (!$isStepFailed) {
            return;
        }

        $html_dump_path = '../behat/results';
        $message = '';
        $session = $this->getSession();
        $page = $session->getPage();
        $date = date('Y-m-d H:i:s');
        $url = $session->getCurrentUrl();
        $html = $page->getContent();

        $feature = $scope->getFeature();
        $feature_file_full = $feature->getFile();

        $base_path = $scope->getEnvironment()->getSuite()->getSetting('paths')[0];
        $feature_file_full = str_replace([$base_path . '/', '.feature'], [
            '',
            '_feature',
        ], $feature_file_full);
        $ff = explode('/', $feature_file_full);
        $ff[] = $scope->getScenario()->getLine();
        array_unshift($ff, 'features');
        $feature_file_name = implode('_', $ff);

        if (!file_exists($html_dump_path) && !mkdir($html_dump_path) && !is_dir($html_dump_path)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $html_dump_path));
        }

        $html = "<!-- HTML dump from behat  \nDate: $date  \nUrl:  $url  -->\n " . $html;

        $htmlCapturePath = $html_dump_path . '/' . $feature_file_name . '.html';

        file_put_contents($htmlCapturePath, $html);

        $message .= "\nHTML available at: " . $htmlCapturePath;

        print $message . PHP_EOL;
    }

}
