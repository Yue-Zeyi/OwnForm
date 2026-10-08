<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\PageUtil;
use think\facade\Db;

/**
 * 页面公开读取（访客端）
 *
 * 只输出已发布、未删除的页面；内容在输出前再次消毒（纵深防御，
 * 即使库里存在历史脏数据也不会直接吐给浏览器）。
 */
class PageViewApi extends BaseController
{
    /**
     * 读取页面内容与内嵌组件数据
     */
    public function read(string $slug)
    {
        $page = Db::name('pages')->where('slug', $slug)
            ->whereNull('deleted_at')->where('status', 1)->find();
        if (!$page) {
            return $this->fail('页面不存在或已下线', 4004);
        }

        $settings = json_decode((string)$page['settings_json'], true) ?: [];
        $settings = PageUtil::normalizeSettings(is_array($settings) ? $settings : []);

        $html = (string)$page['content_html'];
        $html = \app\logic\HtmlSanitizer::clean($html);
        $embeds = PageUtil::extractEmbeds($html);
        $html = PageUtil::replaceEmbeds($html, $embeds);

        // 设了访问密码时，正文与内嵌数据一律不下发：
        // 密码本身绝不返回给前端（否则访客无需输入即可读到内容），
        // 校验改由 unlock 接口完成，凭 session 标记放行。
        $locked = $settings['accessPassword'] !== '';
        if ($locked && !session('page_unlocked_' . $page['id'])) {
            return $this->ok([
                'id'           => (int)$page['id'],
                'title'        => $page['title'],
                'needPassword' => true,
                'locked'       => true,
            ]);
        }

        return $this->ok([
            'id'          => (int)$page['id'],
            'title'       => $page['title'],
            'description' => (string)$page['description'],
            'cover'       => (string)$page['cover'],
            'html'        => $html,
            'embeds'      => self::resolveEmbeds($embeds),
            'theme'       => $settings['theme'],
            'template'    => $settings['template'],
            'footerText'  => $settings['footerText'],
            // 走到这里说明 session 已校验通过（或页面未设密），
            // 必须回 false：前端 load() 以此判断能否进入正文，
            // 若仍回 true 会把已解锁的访客打回密码页
            'needPassword'=> false,
            'seo'         => $settings['seo'],
        ]);
    }

    /**
     * 记录一次浏览
     */
    public function view(string $slug)
    {
        $page = Db::name('pages')->where('slug', $slug)
            ->whereNull('deleted_at')->where('status', 1)->find();
        if ($page) {
            Db::name('pages')->where('id', (int)$page['id'])->inc('view_count')->update();
        }
        return $this->ok([], '');
    }

    /**
     * 校验页面访问密码
     */
    public function unlock(string $slug)
    {
        $page = Db::name('pages')->where('slug', $slug)
            ->whereNull('deleted_at')->where('status', 1)->find();
        if (!$page) {
            return $this->fail('页面不存在或已下线', 4004);
        }
        $settings = json_decode((string)$page['settings_json'], true) ?: [];
        $expected = (string)($settings['accessPassword'] ?? '');
        if ($expected === '') {
            return $this->ok([], '无需密码');
        }
        // 限频：同 IP 对同一页面 10 分钟内最多 8 次失败，
        // 否则可无限速暴力猜解访问密码绕过 needPassword
        $ip = \app\logic\Ip::get($this->request);
        $failKey = 'page_unlock_' . md5($ip . '|' . $slug);
        if ((int)\think\facade\Cache::store('file')->get($failKey, 0) >= 8) {
            return $this->fail('尝试次数过多，请 10 分钟后再试');
        }
        $input = (string)($this->input()['password'] ?? '');
        if (!hash_equals($expected, $input)) {
            \think\facade\Cache::store('file')->set(
                $failKey,
                (int)\think\facade\Cache::store('file')->get($failKey, 0) + 1,
                600
            );
            return $this->fail('访问密码不正确');
        }
        // 校验通过：清失败计数（否则 NAT/办公网同 IP 下，
        // 前一个人失败几次的残留会让下一个人很快被锁）
        \think\facade\Cache::store('file')->delete($failKey);
        // 在会话中标记，后续 read 不再返回 locked 壳
        session('page_unlocked_' . (int)$page['id'], true);
        return $this->ok([], '验证通过');
    }

    /**
     * 把占位符解析为真实组件数据
     *
     * 关键点：每个内嵌对象的字段都来自服务端查询与白名单校验，
     * 不含任何用户可控的原始文本，因此不存在属性注入。
     *
     * @access private
     */
    private static function resolveEmbeds(array $embeds): array
    {
        $out = [];

        foreach ($embeds as $e) {
            $type   = $e['type'];
            $target = $e['target'];

            if ($type === 'url') {
                $out[] = ['type' => 'url', 'url' => $target, 'title' => $target];
                continue;
            }

            if ($type === 'form') {
                $form = Db::name('forms')->where('slug', $target)
                    ->whereNull('deleted_at')->where('status', 1)->find();
                if (!$form) {
                    // 表单已删除或停收：降级为提示，不泄露任何内部信息
                    $out[] = ['type' => 'missing', 'label' => '表单暂不可用'];
                    continue;
                }
                $fs = json_decode((string)$form['settings_json'], true) ?: [];
                $fs = \app\logic\FieldUtil::normalizeSettings(is_array($fs) ? $fs : []);
                $fields = \app\logic\FieldUtil::extractFields(
                    json_decode((string)$form['fields_json'], true) ?: []
                );
                if (!$fields) {
                    $out[] = ['type' => 'missing', 'label' => '该表单暂无内容'];
                    continue;
                }

                $out[] = [
                    'type'       => 'form',
                    'formId'     => (int)$form['id'],
                    'slug'       => $form['slug'],
                    'title'      => $form['title'],
                    'description'=> (string)$form['description'],
                    'fields'     => $fields,
                    'formOption' => is_array($fs['formOption']) ? $fs['formOption'] : [],
                    'submitText' => $fs['submitText'],
                    'theme'      => $fs['theme'],
                    'needPassword' => $fs['accessPassword'] !== '',
                    'limitOnce'  => (bool)$fs['limitOnce'],
                    'captcha'    => in_array($fs['captcha'] ?? 'none', ['image', 'sms', 'geetest'], true)
                                       ? $fs['captcha'] : 'none',
                    'smsPhoneField' => (string)$fs['smsPhoneField'],
                    'geetestCaptchaId' => ($fs['captcha'] ?? '') === 'geetest'
                                       ? \app\logic\Setting::get('geetest_captcha_id') : '',
                ];
                continue;
            }

            if ($type === 'page') {
                // 页面嵌套限制为 1 层，防止 A 引 B 引 A 的无限递归
                $sub = Db::name('pages')->where('slug', $target)
                    ->whereNull('deleted_at')->where('status', 1)->find();
                if (!$sub) {
                    $out[] = ['type' => 'missing', 'label' => '内容暂不可用'];
                    continue;
                }
                $subHtml = \app\logic\HtmlSanitizer::clean((string)$sub['content_html']);
                // 二次嵌套时直接剥掉其中的占位符，只保留纯文本内容
                $subHtml = (string)preg_replace('/\{\{\s*(form|page|url)\s*:[^}]*\}\}/i', '', $subHtml);
                $out[] = [
                    'type'  => 'page',
                    'title' => $sub['title'],
                    'html'  => $subHtml,
                ];
            }
        }

        return $out;
    }
}