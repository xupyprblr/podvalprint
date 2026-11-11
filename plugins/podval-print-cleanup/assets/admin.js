( function( $ ) {
    'use strict';

    function fmtBytes( b ) {
        b = parseInt( b, 10 ) || 0;
        if ( b < 1024 ) return b + ' B';
        if ( b < 1048576 ) return ( b / 1024 ).toFixed( 1 ) + ' KB';
        if ( b < 1073741824 ) return ( b / 1048576 ).toFixed( 1 ) + ' MB';
        return ( b / 1073741824 ).toFixed( 2 ) + ' GB';
    }

    function esc( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }

    function notice( $target, msg, cls ) {
        cls = cls || 'notice-info';
        $target.html( '<div class="notice ' + cls + ' inline"><p>' + msg + '</p></div>' );
    }

    function callAjax( action, data, onSuccess, $btn ) {
        data = data || {};
        data.action = action;
        data.nonce  = PFC.nonce;

        if ( $btn && $btn.length ) $btn.prop( 'disabled', true );

        return $.post( PFC.ajaxUrl, data )
            .done( function( resp ) {
                if ( resp && resp.success ) {
                    onSuccess( resp.data || {} );
                } else {
                    var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'неизвестная ошибка';
                    alert( PFC.i18n.error + msg );
                }
            } )
            .fail( function( xhr ) {
                alert( PFC.i18n.error + 'HTTP ' + xhr.status );
            } )
            .always( function() {
                if ( $btn && $btn.length ) $btn.prop( 'disabled', false );
            } );
    }

    $( function() {

        // ══════════ Предпросмотр ══════════
        $( '#pfc-preview' ).on( 'click', function() {
            var $btn = $( this );
            var $res = $( '#pfc-preview-result' );
            notice( $res, PFC.i18n.running );
            callAjax( 'pfc_preview', {}, function( d ) {
                var html = '<div class="notice notice-info inline"><p>';
                html += '<strong>🔍 Предпросмотр (без удаления):</strong><br>';
                html += 'Заказов под очистку: <strong>' + d.orders_processed + '</strong><br>';
                html += 'Файлов в них: <strong>' + d.files_deleted + '</strong> (' + fmtBytes( d.bytes_deleted ) + ')<br>';
                html += 'Сирот: <strong>' + d.orphans_deleted + '</strong> (' + fmtBytes( d.orphans_bytes || 0 ) + ')';
                if ( d.files_deleted === 0 && d.orphans_deleted === 0 ) {
                    html += '<br><em>Под текущие настройки ничего не попадает.</em>';
                }
                html += '</p></div>';
                $res.html( html );
            }, $btn );
        } );

        // ══════════ Запуск сейчас ══════════
        $( '#pfc-run-now' ).on( 'click', function() {
            if ( ! confirm( PFC.i18n.confirmRun ) ) return;
            var $btn = $( this );
            var $res = $( '#pfc-run-result' );
            notice( $res, PFC.i18n.running );
            callAjax( 'pfc_run_now', {}, function( d ) {
                var html = '<div class="notice notice-success inline"><p>';
                html += '<strong>✅ Выполнено:</strong><br>';
                html += 'Обработано заказов: ' + d.orders_processed + '<br>';
                html += 'Удалено файлов: <strong>' + d.files_deleted + '</strong> (' + fmtBytes( d.bytes_deleted ) + ')<br>';
                html += 'Удалено сирот: <strong>' + d.orphans_deleted + '</strong> (' + fmtBytes( d.orphans_bytes || 0 ) + ')<br>';
                html += 'Длительность: ' + d.duration_ms + ' мс';
                html += '<br><em>Страница обновится через 2 секунды…</em>';
                html += '</p></div>';
                $res.html( html );
                setTimeout( function() { location.reload(); }, 2000 );
            }, $btn );
        } );

        // ══════════ Сканирование призраков ══════════
        $( '#pfc-scan-ghosts' ).on( 'click', function() {
            var $btn = $( this );
            var $res = $( '#pfc-ghosts-result' );
            notice( $res, PFC.i18n.running );
            callAjax( 'pfc_scan_ghosts', {}, function( d ) {
                if ( ! d.count ) {
                    $res.html( '<div class="notice notice-success inline"><p>✅ Файлов-призраков не найдено.</p></div>' );
                    return;
                }
                var html = '<div class="notice notice-warning inline"><p>';
                html += '<strong>👻 Найдено призраков: ' + d.count + '</strong> (' + fmtBytes( d.bytes ) + ')';
                if ( d.preview && d.preview.length ) {
                    html += '<br><small>Первые ' + d.preview.length + ' файлов:</small>';
                    html += '<ul class="pfc-ghost-list">';
                    d.preview.forEach( function( g ) {
                        html += '<li>' + esc( g.rel ) + ' <small>(' + fmtBytes( g.size ) + ')</small></li>';
                    } );
                    html += '</ul>';
                }
                html += '<button type="button" class="button button-link-delete" id="pfc-delete-ghosts">🗑 Удалить все призраки</button>';
                html += '</p></div>';
                $res.html( html );
            }, $btn );
        } );

        // ══════════ Удаление призраков ══════════
        $( document ).on( 'click', '#pfc-delete-ghosts', function() {
            if ( ! confirm( PFC.i18n.confirmGhosts ) ) return;
            var $btn = $( this );
            var $res = $( '#pfc-ghosts-result' );
            notice( $res, PFC.i18n.running );
            callAjax( 'pfc_delete_ghosts', {}, function( d ) {
                var html = '<div class="notice notice-success inline"><p>';
                html += '✅ Удалено файлов: <strong>' + d.count + '</strong> (' + fmtBytes( d.bytes ) + ')';
                html += '<br><em>Страница обновится через 2 секунды…</em>';
                html += '</p></div>';
                $res.html( html );
                setTimeout( function() { location.reload(); }, 2000 );
            }, $btn );
        } );

        // ══════════ Очистка истории ══════════
        $( '#pfc-clear-log' ).on( 'click', function() {
            if ( ! confirm( PFC.i18n.confirmClearLog ) ) return;
            callAjax( 'pfc_clear_log', {}, function() {
                location.reload();
            }, $( this ) );
        } );
    } );

} )( jQuery );
