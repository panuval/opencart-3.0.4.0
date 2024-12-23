<?php
namespace OCM\Elements;
final class Textarea extends Base {
    public function get($params) {
       $params['element'] = $this->getElement($params);
       return $this->render($params);
    }
    private function getElement($params) {
        $class = "form-control";
        if (!empty($params['class']) && strpos($params['class'], 'editor') !== false) {
            $class .= " editor summernote";
        }
        $element = '<textarea class="'.$class.'" name="{name}" id="{id}" placeholder="{placeholder}" rows="{rows}" cols="70">{preset}</textarea>';
        return $element;
    }
}