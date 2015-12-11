<?php
/*
Plugin Name: Обновление всех постов в системе
Plugin URI: https://github.com/systemo-biz/bulk-post-updater
Description: Этот плагин реализует функцию массовой обработки всех постов в системе по очереди из 100 штук
Author: Systemo
Version: 1.0
Author URI: http://systemo.biz/
*/

class BulkPostUpdaterS
{

  function __construct()
  {

    //AJAX callback
    add_action('wp_ajax_bpus_start', array($this,'bpus_start_callback'));
    add_action('wp_ajax_nopriv_bpus_start', array($this,'bpus_start_callback'));

    //Hearbeat API
    add_action( 'admin_enqueue_scripts', array($this, 'heartbeat_enqueue'));
    add_filter( 'heartbeat_send', array($this, 'heartbeat_send_callback'), 10, 2 );

    //Add menu page
    add_action('admin_menu', array($this, 'add_menu_page'));
  }

  //AJAX callback
  function bpus_start_callback(){
    set_transient( 'bpus_start_time', current_time('mysql'), 60); //ставим отметку на 60 секунд по запуску обновления данных
    delete_transient( 'bpus_end' );//удаляем отметку о результате, если работа идет

    $offset = 0; //ставим позицию старта забора данных по умолчанию
    $count = 100; //ставим число одновременно запрашиваемых данных для забора
    if(isset($_REQUEST['offset'])) $offset = $_REQUEST['offset']; //если в запросе передали параметр старта позиций, то присваиваем его в $start

    $posts = get_posts('post_status=publish&post_type=any&numberposts=' . $count . '&offset=' . $offset);


    $i = 0;
    if(isset($posts)) {
      foreach ($posts as $key => $post) {
        wp_update_post(array('ID' => $post->ID));
        $i++;
      }
    }

    //берем значение счетчика импорта продуктов.
    //Если есть то прибавляем число текущих итераций
    //если нет то помещаем туда число текущих итераций
    $itc = get_transient('bpus_count');
    if(isset($itc)) {
      set_transient('bpus_count', $itc + $i, 66);
    } else {
      set_transient('bpus_count', $i, 66);
    }

    //Если есть данные, то выполнение новой порции иначе запись результата
    if($i) {
      //Перезапуск итерации с новой порцией данных
      $offset = $offset + $numberposts;
      $url = admin_url('admin-ajax.php?action=export_product_mss&offset=' . $offset);
      $url_result = wp_remote_get($url);
    } else {
      set_transient('bpus_end', "Работа выполнена", 777);
    }

    wp_send_json_success(current_time('mysql'));

  }



  //Добавляем страницу в меню Инструменты
  function add_menu_page(){
    add_submenu_page(
        $parent_slug = 'tools.php',
        $page_title = "Обновление всех постов",
        $menu_title = "Обновить все посты",
        $capability = "manage_options",
        $menu_slug = "bpu-s",
        $function = array($this, 'bpus_tools_callback')
     );
  }

  function bpus_tools_callback(){
    ?>
    <div id="bpus-wrapper" class="wrap">
        <h1>Инструмент автоматического обновления постов</h1>
      <div class="instruction">
        <p>Эта обработка обновляет все посты в системе очередями по 100 штук и выводит данные через Hearbeat API на этой странице</p>
      </div>
      <button id="bpus-product-import" class="button button-small">Запустить обновление всех постов</button>
      <br>
      <div class="status-wrapper hide-if-js">
        <strong>Статус работы: </strong>
        <ul>
          <li>Результат первой итерации: <span class="first-result">отсутствует</span></li>
          <li>Старт работы: <span class="start-result">ждем данные</span></li>
          <li>Число итераций за последнюю минуту: <span class="countr-result">ждем данные</span></li>
          <li>Результат: <span class="bpus_end-result">ждем данные</span></li>

          <li>Тест-сообщение: <span class="test-result">отсутствует</span></li>
        </ul>
      </div>

      <div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
              $('#bpus-wrapper button').click(function () {

                $('#bpus-wrapper .status-wrapper').show();

                var data = {
            			action: 'bpus_start',
            		};

                $.getJSON(ajaxurl, data, function(response){

                  $('#bpus-wrapper .first-result').text('успех = ' + response.success + ' (' + response.data + ')');

                });
              });
            });
        </script>
      </div>
    </div>
    <?php
  }




  //Получаем количество обработанных записей из кеша для вывода через Hearbeat
  function heartbeat_send_callback($data, $screen_id){

    //Проверка экрана, если не тот, значит прерываем работу
    if('tools_page_bpu-s' != $screen_id) return $data;

    //Если запущна экспорт, то помечаем данные, иначе отключаем передачу данных на клиента
    if(get_transient( 'bpus_start_time')) {
      $data['bpus_start_time'] = get_transient( 'bpus_start_time');
    } else {
      return $data;
    }

    $data['test'] = get_transient( 'test');

    $data['bpus_count'] = get_transient( 'bpus_count');
    $data['bpus_end'] = get_transient( 'bpus_end');

    return $data;
  }



  //Прослушка данных Hearbeat и их вывод в лог
  function heartbeat_footer_js(){

    //Если это не страница обработки, то не выводим прослушку Hearbeat
    $cs = get_current_screen();
    if('tools_page_bpu-s' != $cs->id) return;

    ?>
        <script type="text/javascript" id="bpus-hearbeat">
        (function($){

            $(document).on( 'heartbeat-tick', function(e, data) {

              console.log('Сообщение HB для импорта продуктов готово: ' + data['mss_product_import_start']);

              //Если есть данное о старте, то выводим сообщение и работаем, иначе прирываем работу
              if ( data['bpus_start_time'] ){
                $('#bpus-wrapper .start-result').text(data['bpus_start_time']);
              } else {
                return;
              }

              //Добавляем тестовое сообщение. Используется для отладки
              $('#bpus-wrapper .test-result').text(data['test']);
              $('#bpus-wrapper .countr-result').text(data['bpus_count']);
              $('#bpus-wrapper .bpus_end-result').text(data['bpus_end']);

              return;
            });

        }(jQuery));
        </script>
    <?php

  }




  //Надо убедиться что скрипт Hearbeat есть и напечатать скрипт управления
  function heartbeat_enqueue() {
    // Make sure the JS part of the Heartbeat API is loaded.
    wp_enqueue_script( 'heartbeat' );
    add_action( 'admin_print_footer_scripts', array($this, 'heartbeat_footer_js'), 20 );
  }
}

$TheBulkPostUpdaterS = new BulkPostUpdaterS;
