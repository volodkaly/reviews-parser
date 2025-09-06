<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class ParseReviewsWazbee extends Command
{
    protected $signature = 'parse:wazbee';
    protected $description = 'Parsing reviews from Wazbee Trustpilot and save to a file';

    public function handle()
    {
        // 1. Налаштування Chrome
        $options = new ChromeOptions();
        $options->addArguments(['--headless', '--disable-gpu']); // без GUI

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        // 2. Підключення до WebDriver
        $driver = RemoteWebDriver::create('http://localhost:9515', $capabilities);

        // 3. Відкриваємо сторінку
        $driver->get('https://www.trustpilot.com/review/wazbee.casino');

        // 4. Підготовка файлу для збереження
        $filePath = storage_path('wazbee_reviews_all.txt');
        file_put_contents($filePath, ""); // очищаємо файл перед записом

        $pages = 0;
        $maxPages = 10000;


        do {
            $reviewsText = '';

            // 5. Чекаємо, поки відгуки завантажаться
            $driver->wait(3)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('section.styles_reviewListContainer__2bg_p')
                )
            );

            //Всі аватарки та рейтинги
            $imgUrls = [];
            $ratings = [];

            try {
                //Текст усіх відгуків
                $reviewsContainer = $driver->findElement(
                    WebDriverBy::cssSelector('section.styles_reviewListContainer__2bg_p')
                );
                $reviewsText = $reviewsContainer->getText();

                $avatarElements = $reviewsContainer->findElements(
                    WebDriverBy::cssSelector('[data-testid="consumer-avatar"]')
                );

                foreach ($avatarElements as $avatar) {
                    try {
                        $imgInside = $avatar->findElements(WebDriverBy::cssSelector('img'));

                        if (count($imgInside) > 0) {
                            $src = $imgInside[0]->getAttribute('src');
                            $imgUrls[] = $src && str_contains($src, 'png') ? $src : 'no avatar image';
                        } else {
                            $imgUrls[] = 'no avatar image';
                        }
                    } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                        $imgUrls[] = 'no avatar image';
                        continue;
                    }
                }

                $imgElements = $reviewsContainer->findElements(WebDriverBy::cssSelector('img'));
                foreach ($imgElements as $img) {
                    try {
                        $rate = $img->getAttribute('src');
                        if ($rate && str_contains($rate, 'star') && str_contains($rate, 'svg')) {
                            $ratings[] = $rate;
                        }
                    } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                        $this->info("не знайдено рейтинг");
                        continue;
                    }
                }
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                $this->info("щось не знайдено");
                continue;
            }

            // 3. Формуємо запис у файл
            $content = $reviewsText
                . "\n\nImages:\n" . implode("\n", $imgUrls)
                . "\n\nRatings:\n" . implode("\n", $ratings)
                . "\n\n***End_of_page_$pages***\n\n";

            file_put_contents($filePath, $content, FILE_APPEND);

            $pages++;
            $this->info("Обробляю сторінку $pages...");

            // Перевірка обмеження по сторінках
            if ($pages >= $maxPages) {
                break;
            }

            // 7. Перевіряємо кнопку "Next"
            try {
                $nextButton = $driver->findElement(
                    WebDriverBy::cssSelector('a[data-pagination-name="pagination-button-next"]')
                );

                $disabledAttr = $nextButton->getAttribute('aria-disabled');


                if ($disabledAttr === 'true') {

                    break; // друга ітерація вже була — виходимо



                }
                // Прибираємо банер, якщо є
                $driver->executeScript("
                    let banner = document.querySelector('.onetrust-pc-dark-filter');
                    if (banner) { banner.style.display = 'none'; }
                ");

                // Скролимо до кнопки і клікаємо
                $driver->executeScript("arguments[0].scrollIntoView(true);", [$nextButton]);
                sleep(3);
                $driver->executeScript("arguments[0].click();", [$nextButton]);

                sleep(3);

                $driver->wait(5)->until(
                    WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                        WebDriverBy::cssSelector('img[src*="stars"]')
                    )
                );

            } catch (\Facebook\WebDriver\Exception\NoSuchElementException $e) {
                break;
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {

                continue;
            }

        } while (true);

        $this->info("Зібрано $pages сторінок відгуків. Дані збережено у файл: $filePath");

        // 8. Закриваємо драйвер
        $driver->quit();
    }
}
