<?php

namespace IdnoPlugins\Text {
    
    class Main extends \Idno\Common\Plugin
    {

        function registerPages()
        {
	    
        }

        function registerTranslations()
        {

            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'ipfs', dirname(__FILE__) . '/languages/'
                )
            );
        }

    }

}
