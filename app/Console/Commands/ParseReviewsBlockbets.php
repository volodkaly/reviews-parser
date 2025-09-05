<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class ParseReviewsBlockbets extends Command
{
    protected $signature = 'parse:blockbets';
    protected $description = 'Parsing reviews from Blockbets Trustpilot and save to a file';

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
        $driver->get('https://www.trustpilot.com/review/blockbets.casino');

        // 4. Підготовка файлу для збереження
        $filePath = storage_path('blockbets_reviews_all.txt');
        file_put_contents($filePath, ""); // очищаємо файл перед записом

        $hasNext = true;
        $pages = 0;
        $maxPages = 1000; // ліміт на кількість сторінок

        do {
            // 5. Чекаємо, поки відгуки завантажаться
            $driver->wait(3)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('section.styles_reviewListContainer__2bg_p')
                )
            );

            // 6. Отримуємо текст відгуків на поточній сторінці
            $reviewsContainer = $driver->findElement(
                WebDriverBy::cssSelector('section.styles_reviewListContainer__2bg_p')
            );

            // 1. Текст усіх відгуків
            $reviewsText = $reviewsContainer->getText();

            // 2. Всі аватарки всередині контейнера
            $imgUrls = [];
            $ratings = [];

            try {
                // Знаходимо всі аватарні контейнери (і з картинками, і без)
                $avatarElements = $reviewsContainer->findElements(WebDriverBy::cssSelector('[data-testid="consumer-avatar"]'));

                foreach ($avatarElements as $avatar) {
                    try {
                        // Пробуємо знайти картинку всередині аватарного контейнера
                        $imgInside = $avatar->findElements(WebDriverBy::cssSelector('img'));

                        if (count($imgInside) > 0) {
                            // Якщо картинка є — беремо її src
                            $src = $imgInside[0]->getAttribute('src');
                            $imgUrls[] = $src && str_contains($src, 'png') ? $src : 'no avatar image';
                        } else {
                            // Якщо немає картинки — явно пишемо, що немає
                            $imgUrls[] = 'no avatar image';
                        }

                    } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                        $imgUrls[] = 'no avatar image';
                        continue;
                    }
                }

                // Далі збираємо рейтинги як раніше
                $imgElements = $reviewsContainer->findElements(WebDriverBy::cssSelector('img'));
                foreach ($imgElements as $img) {
                    try {
                        $alt = $img->getAttribute('alt');
                        if ($alt && str_contains($alt, 'Rated')) {
                            $ratings[] = $alt;
                        }
                    } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                        continue;
                    }
                }
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                continue;
            }

            // 3. Формуємо запис у файл
            $content = $reviewsText
                . "\n\nImages:\n" . implode("\n", $imgUrls)
                . "\n\nRatings:\n" . implode("\n", $ratings)
                . "\n\n***End_of_page***\n\n";

            file_put_contents($filePath, $content, FILE_APPEND);

            $pages++;
            $this->info("Обробляю сторінку $pages...");
            if ($pages >= $maxPages) {
                $hasNext = false;
                break;
            }

            // 7. Стабільний клік на "Next" з обробкою StaleElement
            try {
                $nextButton = $driver->findElement(
                    WebDriverBy::cssSelector('a[data-pagination-name="pagination-button-next"]')
                );

                if ($nextButton->isDisplayed() && $nextButton->isEnabled()) {
                    // Сховати банер
                    $driver->executeScript("
            let banner = document.querySelector('.onetrust-pc-dark-filter');
            if (banner) { banner.style.display = 'none'; }
        ");

                    // Прокрутка і клік через JS
                    $driver->executeScript("arguments[0].scrollIntoView(true);", [$nextButton]);
                    sleep(0.1);
                    $driver->executeScript("arguments[0].click();", [$nextButton]);

                    sleep(0.1); // чекаємо нові відгуки
                    $hasNext = true;
                } else {
                    $hasNext = false;
                }
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException $e) {
                sleep(0.1);
                continue;
            } catch (\Facebook\WebDriver\Exception\NoSuchElementException $e) {
                $hasNext = false;
            }


        } while ($hasNext);

        $this->info("Зібрано $pages сторінок відгуків. Дані збережено у файл: $filePath");

        // 8. Закриваємо драйвер
        $driver->quit();
    }
}
