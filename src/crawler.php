<?php


class Crawler
{
    public $propertyIndex = 0;
    public $sectionNames   = array('委員會','學歷','電話','經歷','傳真','通訊處','links','到職日期');
    public $propertyNames = array('姓名', '英文姓名', '性別', '黨籍', '黨團', '選區', '生日');


    public function isPropertyName($p) {
        list($n) = preg_split('#：#', $p);
        return in_array($n,$this->propertyNames);
    }

    public function hasSubItems($p) {
        return preg_match('#：#', $p);
    }

    public function parseLabel($p) {
        list($n) = preg_split('#：#', $p);
        return $n;
    }

    public function isSectionName($p) {
        list($n) = preg_split('#：#', $p);
        return in_array($n,$this->sectionNames);
    }

    public function getSubList($parts) {

    }


    public function getSubPropertiesFrom($parts) {
        $properties = array();
        if ( ! isset($parts[$this->propertyIndex]) ) {
            return $properties;
        }
        while( isset($parts[$this->propertyIndex+1]) ) {
            // recursively take the subitems
            if ( $this->hasSubItems($parts[$this->propertyIndex+1] ) ) {
                if ( $this->isSectionName($parts[$this->propertyIndex+1]) 
                    || $this->isPropertyName($parts[$this->propertyIndex+1]) )
                    return $properties;
                // handle
                $this->propertyIndex++;
                $n = $this->parseLabel( $parts[$this->propertyIndex] );
                $properties[$n] = $this->getSubPropertiesFrom($parts);
            } else {

                // take all list and return
                while(
                    isset($parts[$this->propertyIndex+1])
                    && ! $this->hasSubItems($parts[$this->propertyIndex+1]) ) 
                {
                    $this->propertyIndex++;
                    $properties[] = $parts[$this->propertyIndex];
                }
                return $properties;
            }
        }
        return $properties;
    }



    public function innerHTML($doc, $el)
    {
        // from http://php.net/manual/en/book.dom.php
        $html = trim($doc->saveHTML($el));
        $tag = $el->nodeName;
        return preg_replace('@^<' . $tag . '[^>]*>|</' . $tag . '>$@', '', $html);
    }

    public function findDomByCondition($doc, $tag, $key, $value)
    {
        $ret = [];
        foreach ($doc->getElementsByTagName($tag) as $dom) {
            if ($dom->getAttribute($key) !== $value) {
                continue;
            }
            $ret[] = $dom;
        }
        return $ret;

    }

    public function getAbsoluteURL($source_url, $relative_url)
    {
        $url_parts = parse_url($relative_url);
        if (array_key_exists('scheme', $url_parts)) {
            return $relative_url;
        }
        $absolute = http_build_url($source_url, $url_parts, HTTP_URL_JOIN_PATH);
        return $absolute;
    }

    public function getBodyFromURL($url, $try = 1)
    {
        error_log($url);
        if ($try > 3) {
            throw new Exception('try 3 times...');
        }
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($curl);
        if (200 !== curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
            return $this->getBodyFromURL($url, $try + 1);
        }
        return $ret;
    }

    public function main($url)
    {
        $doc = new DOMDocument();
        $full_body = $this->getBodyFromURL($url);
        @$doc->loadHTML($full_body);

        $persons = [];
        foreach ($this->findDomByCondition($doc, 'td', 'class', 'leg03_news_search_03') as $td_dom) {
            $person = new StdClass;

            $a_dom = $td_dom->getElementsByTagName('a')->item(0);
            $link = 'http://www.ly.gov.tw' . $a_dom->getAttribute('href');
            $name = trim($a_dom->nodeValue);

            $person->{'姓名'} = $name;
            $person->{'link'} = $link;

            $persondoc = new DOMDocument();
            @$persondoc->loadHTML($this->getBodyFromURL($link));

            // 1 - 簡介
            $ul_dom = $this->findDomByCondition($persondoc, 'ul', 'style', 'list-style-position:outside;')[0];

            $list = array('姓名', '英文姓名', '性別', '黨籍', '黨團', '選區', '生日');

            $text = $ul_dom->textContent;
            $parts = preg_split('#\r\n#', $text);
            $parts = array_values(array_filter(array_map(function($part) { return trim($part); }, $parts),
                    function($part) { return $part ? true : false; }));

            $properties = array();
            for ( $this->propertyIndex = 0 ; $this->propertyIndex < count($parts) ; $this->propertyIndex++ ) {
                if (! isset($parts[$this->propertyIndex]))
                    continue;

                $part = $parts[$this->propertyIndex];
                if ( preg_match('#：#', $part ) ) {
                    list($key, $value) = preg_split('#：#', $part);

                    // for normal property
                    if ( in_array($key, $this->propertyNames) ) {
                        $properties[ $key ] = trim($value);
                    }
                    elseif ( in_array($key, $this->sectionNames) ) {
                        $properties[ $key ] = $this->getSubPropertiesFrom($parts);
                    }
                    elseif ( strlen($value) == 0 ) {
                        // handle something like "到職日期"
                        // for property key without value (we should take the next token)
                        $properties[ $key ] = $parts[$this->propertyIndex++];
                    }
                }
            }
            # var_dump( $parts ); 
            var_dump( $properties ); 
            sleep(1);
            continue;

            foreach ($ul_dom->getElementsByTagName('li') as $li_dom) {
                var_dump( $li_dom->nodeValue );
                list($key, $value) = explode('：', trim($li_dom->nodeValue), 2);

                if (in_array($key, $list)) {
                    $person->{$key} = trim($value);
                }
                if ('委員會' == $key) {
                    $committees = array();
                    foreach ( preg_split("#<br/?>#", $this->innerHTML($persondoc, $li_dom)) as $body) {
                        $body = trim($body);
                        if ( strlen($body) == 0 )
                            continue;

                        list($key, $value) = explode('：', $body);
                        if (trim($key) == '委員會') {

                        } elseif (trim($key) == '到職日期') {
                            $person->{'到職日期'} = trim($value);
                        } else {
                            $committees[] = array($key, trim($value));
                        }
                    }
                    $person->{'委員會'} = $committees;
                } else {
                    throw new Exception('出現其他東西');
                }
            }
            // 照片
            foreach ($persondoc->getElementsByTagName('img') as $img_dom) {
                if ($img_dom->getAttribute('class') != 'leg03_pic') {
                    continue;
                }
                $person->{'pic'} = 'http://www.ly.gov.tw' . $img_dom->getAttribute('src');

            }

            // 學歷
            $ul_doms = $this->findDomByCondition($persondoc, 'ul', 'style', 'list-style-position:outside;');
            $map = array(
                1 => '學歷',
                2 => '電話',
                3 => '經歷',
                4 => '傳真',
                5 => '通訊處',
            );
            $skip_map = array('簡介');

            foreach ($map as $n) {
                $person->{$n} = array();
            }

            foreach ($ul_doms as $ul_dom) {
                $name = strval($ul_dom->previousSibling->previousSibling->nodeValue);
                if (!in_array($name, $map)) {
                    if (!in_array($name, $skip_map)) {
                        error_log($name);
                    }
                    continue;
                }

                $rows = array();
                if ($ul_dom->getElementsByTagName('div')->length == 0) {
                    $person->{$name} = $rows;
                    continue;
                }
                foreach ($ul_dom->getElementsByTagName('div') as $div_dom) {
                    foreach (explode('<br>', $this->innerHTML($persondoc, $div_dom)) as $text) {
                        if ('' !== trim($text)) {
                            $rows[] = trim(strip_tags($text));
                        }
                    }
                }
                $person->{$name} = $rows;
            }

            // 加上留言版和信箱
            $links = new StdClass;
            foreach ($this->findDomByCondition($persondoc, 'td', 'class', 'leg03_titbg06') as $td_dom) {
                $a_doms = $td_dom->getElementsByTagName('a');
                if ($a_doms->length != 1) {
                    continue;
                }
                $a_dom = $a_doms->item(0);
                $links->{$a_dom->nodeValue} = $this->getAbsoluteURL($link, $a_dom->getAttribute('href'));
            }
            $person->links = $links;
            $persons[] = $person;
        }
        echo json_encode($persons, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

if (isset($_SERVER['argv'][1])) {
    $url = $_SERVER['argv'][1];
} else {
    $url = 'http://www.ly.gov.tw/03_leg/0301_main/legList.action';
}
$c = new Crawler;
$c->main($url);
