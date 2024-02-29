<?php

namespace behat\features\bootstrap;

use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\DrupalExtension\Manager\FastLogoutInterface;
use const PHP_EOL;

/**
 * Defines application features from the specific context.
 */
class BaseContext extends RawDrupalContext
{

    /**
     * Keep track of entities, so they can be cleaned up.
     *
     * @var array
     */
    protected static $entities = [];

    /**
     * Initializes context.
     *
     * Every scenario gets its own context instance.
     * You can also pass arbitrary arguments to the
     * context constructor through behat.yml.
     */
    public function __construct()
    {
    }

    /**
     * Clear the cache tags cache, then visit the page.
     *
     * Cache tags are optimized to only reset once per "request". A test scenario
     * cam potentially make many page requests, but they are seen as a single
     * request from the cache perspective. This allows us to reset the cache tags
     * after each page visit within a scenario. The static cache is already
     * normally cleared by behat after each scenario.
     *
     * @param string $path
     *   The path to visit.
     */
    public function clearCacheThenVisit(string $path): void
    {
        $this->getDriver()->clearStaticCaches();
        $this->getSession()->visit($this->locatePath($path));
    }

    /**
     * Prints the console messages.
     */
    public function printConsoleMessages(): void
    {
        /** @var \DMore\ChromeDriver\ChromeDriver $driver */
        $driver = $this->getMink()->getSession()->getDriver();
        $messages = $driver->getConsoleMessages();
        $this->clearConsoleMessages();
        try {
            $message_string = json_encode($messages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            print $message_string . PHP_EOL;
        } catch (Exception $exception) {

        }
    }

    /**
     * Clears the console messages.
     */
    public function clearConsoleMessages(): void
    {
        /** @var \DMore\ChromeDriver\ChromeDriver $driver */
        $driver = $this->getMink()->getSession()->getDriver();
        $driver->clearConsoleMessages();
    }

    /**
     * Log out from the website.
     *
     * @Given I log out from IPC Edge
     */
    public function iLogOutFromIpcEdge()
    {
      $this->spin(function ($context) {
        $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_LOGOUT);
        return true;
      });
    }

  /**
   * Remove any created users.
   *
   * @AfterScenario
   */
  public function cleanUsers()
  {
    // Remove any users that were created.
    if ($this->userManager->hasUsers()) {
      foreach ($this->userManager->getUsers() as $user) {
        $saved_user = user_load_by_mail($user->mail);
        $saved_user->delete();
      }
      $this->userManager->clearUsers();
      // If the authentication manager supports logout, no need to check if the user is logged in.
      if ($this->getAuthenticationManager() instanceof FastLogoutInterface) {
        $this->logout(true);
      } elseif ($this->loggedIn()) {
        $this->logout();
      }
    }
  }

}
