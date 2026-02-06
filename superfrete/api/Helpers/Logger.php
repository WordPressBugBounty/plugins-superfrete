<?php

namespace SuperFrete_API\Helpers;

if (!defined('ABSPATH')) {
    exit; // Segurança para evitar acesso direto
}

class Logger {

    private static $log_file;

    /**
     * Inicializa a classe e define o caminho do log.
     */
    public static function init() {
        if (!self::$log_file) {
            $upload_dir = wp_upload_dir();
            self::$log_file = trailingslashit($upload_dir['basedir']) . 'superfrete.log';

            // Garante que a pasta de logs exista
            $log_dir = dirname(self::$log_file);

            if (!file_exists($log_dir)) {
                mkdir($log_dir, 0755, true);
            }
        }
    }

    /**
     * Verifica se o modo debug está ativado.
     * Respeita WP_DEBUG ou a opção superfrete_debug_mode.
     *
     * @return bool
     */
    public static function is_debug_enabled() {
        // Permite ativar via opção do WordPress (para controle independente)
        $debug_option = get_option('superfrete_debug_mode', 'auto');

        if ($debug_option === 'enabled') {
            return true;
        }

        if ($debug_option === 'disabled') {
            return false;
        }

        // Modo 'auto': respeita WP_DEBUG
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Registra uma mensagem no log.
     *
     * @param string|array $message Mensagem a ser registrada (pode ser string ou array para JSON).
     * @param string $level Nível do log (DEBUG, INFO, WARNING, ERROR).
     */
    public static function log($message, $level = 'ERROR') {
        // DEBUG logs só são registrados se debug estiver habilitado
        if (strtoupper($level) === 'DEBUG' && !self::is_debug_enabled()) {
            return;
        }

        self::init();

        // Se for um array (como uma resposta da API), converte para JSON formatado
        if (is_array($message)) {
            $message = wp_json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            // Remove caracteres Unicode escapados de strings normais
            $message = json_decode('"' . $message . '"');
        }

        $log_entry = sprintf("[%s] [%s] %s\n", date("Y-m-d H:i:s"), strtoupper($level), $message);
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Registra uma mensagem de debug.
     * Só será registrada se WP_DEBUG estiver ativo ou superfrete_debug_mode = 'enabled'.
     *
     * @param string|array $message Mensagem a ser registrada.
     * @param string $context Contexto opcional para prefixar a mensagem.
     */
    public static function debug($message, $context = '') {
        if ($context) {
            $message = "[$context] $message";
        }
        self::log($message, 'DEBUG');
    }

    /** 
     * Obtém o conteúdo do log.
     *
     * @return string Conteúdo do log ou mensagem de erro se não existir.
     */
    public static function get_log() {
        self::init();
        return file_exists(self::$log_file) ? file_get_contents(self::$log_file) : 'Nenhum log encontrado.';
    }

    /**
     * Limpa o log.
     */
    public static function clear_log() {
        self::init();
        file_put_contents(self::$log_file, '');
    }
}