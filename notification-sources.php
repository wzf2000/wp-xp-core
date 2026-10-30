<?php
/** Private, bounded source dispatch. Sources own their data, identifiers and read semantics. */
defined('ABSPATH') || exit();
final class WP_XP_Core_Notification_Sources
{
    private static array $sources = [];
    public static function register(string $id, array $definition): bool
    {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) ||
            $id === 'xp' ||
            isset(self::$sources[$id]) ||
            count(self::$sources) >= 10 ||
            array_diff(array_keys($definition), ['label', 'available', 'listing', 'mark']) ||
            !is_string($definition['label'] ?? null) ||
            trim($definition['label']) === '' ||
            mb_strlen($definition['label']) > 80 ||
            !is_callable($definition['available'] ?? null) ||
            !is_callable($definition['listing'] ?? null) ||
            !is_callable($definition['mark'] ?? null)
        ) {
            return false;
        }
        self::$sources[$id] = $definition;
        return true;
    }
    private static function permission($nonce)
    {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return new WP_Error('login_required', wp_xp_core_t('请先登录。'), ['status' => 401]);
        }
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('nonce', wp_xp_core_t('验证已过期，请刷新页面。'), [
                'status' => 403,
            ]);
        }
        return true;
    }
    public static function request(string $action, array $input, $nonce)
    {
        $permission = self::permission($nonce);
        if ($permission !== true) {
            return $permission;
        }
        try {
            if ($action === 'notification-sources') {
                if ($input) {
                    throw new InvalidArgumentException('Invalid source fields');
                }
                $out = [['id' => 'xp', 'label' => wp_xp_core_t('经验通知')]];
                foreach (self::$sources as $id => $source) {
                    try {
                        if (call_user_func($source['available'], get_current_user_id()) === true) {
                            $out[] = ['id' => $id, 'label' => $source['label']];
                        }
                    } catch (Throwable $e) {
                        /* One optional source cannot hide the independent inbox. */
                    }
                }
                return ['sources' => $out];
            }
            if (
                array_diff(
                    array_keys($input),
                    $action === 'notification-source-list'
                        ? ['source', 'cursor', 'limit']
                        : ['source', 'id'],
                )
            ) {
                throw new InvalidArgumentException('Invalid source fields');
            }
            $id = $input['source'] ?? null;
            if (!is_string($id) || !isset(self::$sources[$id])) {
                throw new InvalidArgumentException('Unknown notification source');
            }
            $source = self::$sources[$id];
            if (call_user_func($source['available'], get_current_user_id()) !== true) {
                return new WP_Error('disabled', wp_xp_core_t('通知来源不可用。'), [
                    'status' => 404,
                ]);
            }
            if ($action === 'notification-source-read') {
                if (
                    !is_string($input['id'] ?? null) ||
                    strlen($input['id']) > 191 ||
                    $input['id'] === ''
                ) {
                    throw new InvalidArgumentException('Invalid notification id');
                }
                return call_user_func($source['mark'], get_current_user_id(), $input['id']);
            }
            $limit = $input['limit'] ?? 12;
            $cursor = $input['cursor'] ?? '';
            if (
                !is_int($limit) ||
                $limit < 1 ||
                $limit > 50 ||
                !is_string($cursor) ||
                strlen($cursor) > 191
            ) {
                throw new InvalidArgumentException('Invalid notification window');
            }
            $result = call_user_func($source['listing'], get_current_user_id(), $cursor, $limit);
            if (is_wp_error($result)) {
                return $result;
            }
            if (
                !is_array($result) ||
                !is_array($result['items'] ?? null) ||
                count($result['items']) > $limit ||
                !(
                    is_null($result['next_cursor'] ?? null) ||
                    (is_string($result['next_cursor']) && strlen($result['next_cursor']) <= 191)
                )
            ) {
                throw new RuntimeException('Invalid source projection');
            }
            foreach ($result['items'] as &$item) {
                if (
                    !is_array($item) ||
                    !is_string($item['id'] ?? null) ||
                    ($item['id'] === '' || strlen($item['id']) > 191) ||
                    !is_string($item['title'] ?? null) ||
                    !is_string($item['message'] ?? null) ||
                    !is_string($item['day'] ?? null) ||
                    !is_bool($item['read'] ?? null)
                ) {
                    throw new RuntimeException('Invalid notification item');
                }
                if (
                    strlen($item['title']) > 1000 ||
                    strlen($item['message']) > 4000 ||
                    strlen($item['day']) > 80
                ) {
                    throw new RuntimeException('Oversized notification item');
                }
                if (isset($item['url'])) {
                    if (
                        !is_string($item['url']) ||
                        strlen($item['url']) > 2048 ||
                        !in_array(
                            strtolower((string) wp_parse_url($item['url'], PHP_URL_SCHEME)),
                            ['http', 'https'],
                            true,
                        )
                    ) {
                        throw new RuntimeException('Invalid notification URL');
                    }
                    $item['url'] = esc_url_raw($item['url'], ['http', 'https']);
                }
            }
            unset($item);
            return $result;
        } catch (InvalidArgumentException $e) {
            return new WP_Error('input', wp_xp_core_t('通知查询字段无效。'), ['status' => 400]);
        } catch (Throwable $e) {
            return new WP_Error('storage', wp_xp_core_t('通知暂时无法读取，请重试。'), [
                'status' => 503,
            ]);
        }
    }
    public static function companion(): void
    {
        wp_xp_core_register_notification_source('companion', [
            'label' => 'PageNest Companion',
            'available' => static fn($uid) => function_exists('pagenest_notifications_enabled') &&
                function_exists('pagenest_notifications_projection') &&
                function_exists('pagenest_notifications_read') &&
                pagenest_notifications_enabled(),
            'listing' => static function ($uid, $cursor, $limit) {
                if ($cursor !== '' && !preg_match('/^(0|[1-9][0-9]{0,2})$/D', $cursor)) {
                    return new WP_Error('input', wp_xp_core_t('通知分页或编号无效。'));
                }
                $data = pagenest_notifications_projection();
                if (is_wp_error($data)) {
                    return $data;
                }
                $offset = $cursor === '' ? 0 : (int) $cursor;
                if ($offset > 100) {
                    return new WP_Error('input', wp_xp_core_t('通知分页或编号无效。'));
                }
                $items = [];
                foreach (array_slice($data['items'], $offset, $limit) as $item) {
                    $items[] = [
                        'id' => $item['kind'] . ':' . $item['id'],
                        'title' => $item['title'],
                        'message' => $item['author'],
                        'day' => $item['created'],
                        'read' => $item['read'],
                        'url' => $item['url'],
                    ];
                }
                return [
                    'items' => $items,
                    'next_cursor' =>
                        count($data['items']) > $offset + $limit
                            ? (string) ($offset + $limit)
                            : null,
                ];
            },
            'mark' => static function ($uid, $id) {
                if (!preg_match('/^(comment_reply|paragraph_comment):([1-9][0-9]*)$/D', $id, $m)) {
                    return new WP_Error('input', wp_xp_core_t('通知编号无效。'));
                }
                $request = new WP_REST_Request('POST');
                $request->set_param('kind', $m[1]);
                $request->set_param('id', $m[2]);
                $response = pagenest_notifications_read($request);
                if (is_wp_error($response)) {
                    return $response;
                }
                if (
                    !($response instanceof WP_REST_Response) ||
                    $response->get_status() < 200 ||
                    $response->get_status() >= 300
                ) {
                    return new WP_Error('storage', wp_xp_core_t('通知设置未能保存，请重试。'), [
                        'status' => 503,
                    ]);
                }
                return ['saved' => true];
            },
        ]);
    }
}
function wp_xp_core_register_notification_source(string $id, array $definition): bool
{
    return WP_XP_Core_Notification_Sources::register($id, $definition);
}
add_action('plugins_loaded', [WP_XP_Core_Notification_Sources::class, 'companion'], 30);
