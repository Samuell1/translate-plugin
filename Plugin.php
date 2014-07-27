<?php namespace RainLab\Translate;

use App;
use Lang;
use Event;
use Backend;
use Cms\Classes\Content;
use System\Classes\PluginBase;
use RainLab\Translate\Models\Message;
use RainLab\Translate\Classes\Translate;

/**
 * Translate Plugin Information File
 */
class Plugin extends PluginBase
{

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Translate',
            'description' => 'Enables multi-lingual sites.',
            'author'      => 'RainLab',
            'icon'        => 'icon-language'
        ];
    }

    public function boot()
    {
        /*
         * Set the page context for translation caching.
         */
        Event::listen('cms.page.beforeDisplay', function($controller, $url, $page) {
            if (!$page) return;
            Message::setContext(Translate::instance()->getLocale(), $page->url);
        });

        /*
         * Adds language suffixes to content files.
         */
        Event::listen('cms.page.beforeRenderContent', function($controller, $name) {
            $locale = Translate::instance()->getLocale();
            $newName = substr_replace($name, '.'.$locale, strrpos($name, '.'), 0);
            if (($content = Content::loadCached($controller->getTheme(), $newName)) !== null)
                return $content;
        });
    }

    public function registerSettings()
    {
        return [
            'messages' => [
                'label'       => 'Messages',
                'description' => 'Translate strings used throughout the front-end.',
                'icon'        => 'icon-list-alt',
                'url'         => Backend::url('rainlab/translate/messages'),
                'order'       => 550,
                'category'    => 'Translation',
            ],
            'locales' => [
                'label'       => 'Languages',
                'description' => 'Set up languages that can be used on the front-end.',
                'icon'        => 'icon-language',
                'url'         => Backend::url('rainlab/translate/locales'),
                'order'       => 550,
                'category'    => 'Translation',
            ]
        ];
    }

    /**
     * Register new Twig variables
     * @return array
     */
    public function registerMarkupTags()
    {
        return [
            'filters' => [
                '_' => [$this, 'translateString'],
                '__' => [$this, 'translatePlural'],
            ]
        ];
    }

    public function registerFormWidgets()
    {
        return [
            'RainLab\Translate\FormWidgets\MLText' => [
                'label' => 'Text (ML)',
                'alias' => 'mltext'
            ]
        ];
    }

    public function translateString($string, $params = [])
    {
        return Message::trans($string, $params);
    }

    public function translatePlural($string, $count = 0, $params = [])
    {
        return Lang::choice($string, $count, $params);
    }

}
