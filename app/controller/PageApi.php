<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\OpLog;
use app\logic\PageUtil;
use app\logic\Setting;
use think\facade\Db;

/**
 * 自定义页面管理（仅登录用户，数据隔离与表单一致）
 *
 * 所有路由均挂 AdminAuth 中间件；成员只能看到和管理自己创建的页面。
 */
class PageApi extends BaseController
{
    /**
     * 页面列表（含回收站切换）
     */
    public function index()
    {
        $page    = max(1, (int)input('page', 1));
        $size    = min(100, max(1, (int)input('size', 20)));
        $keyword = trim((string)input('keyword', ''));
        $status  = input('status', '');
        $deleted = (int)input('deleted', 0) === 1;

        $query = $deleted
            ? Db::name('pages')->whereNotNull('deleted_at')->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            : $this->pageQuery();

        if ($keyword !== '') {
            $query->whereLike('title', '%' . self::like($keyword) . '%');
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int)$status);
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')
            ->field('id, title, description, slug, cover, content_type, status, view_count, user_id, created_at, updated_at')
            ->page($page, $size)
            ->select()
            ->toArray();

        $isAdmin = $this->isAdmin();
        if ($isAdmin && $list) {
            $uids      = array_unique(array_column($list, 'user_id'));
            $nicknames = Db::name('users')->whereIn('id', $uids)->column('nickname', 'id');
            foreach ($list as &$row) {
                $row['creator'] = $nicknames[$row['user_id']] ?? '';
            }
            unset($row);
        }

        foreach ($list as &$row) {
            // 回收站中的页面没有可分享的地址
            $row['shareUrl'] = $deleted ? '' : PageUtil::shareUrl($row['slug']);
        }
        unset($row);

        return $this->ok([
            'list' => $list, 'total' => $total,
            'page' => $page, 'size' => $size, 'deleted' => $deleted ? 1 : 0,
        ]);
    }

    /**
     * 页面详情
     */
    public function read(int $id)
    {
        $page = $this->loadOwnedPage($id);
        if (!$page) {
            return $this->fail('页面不存在', 404);
        }
        $settings = json_decode((string)$page['settings_json'], true) ?: [];

        return $this->ok([
            'id'          => (int)$page['id'],
            'title'       => $page['title'],
            'description' => (string)$page['description'],
            'slug'        => $page['slug'],
            'cover'       => (string)$page['cover'],
            'contentType' => (int)$page['content_type'],
            'content'     => (string)$page['content_src'],
            'settings'    => PageUtil::normalizeSettings(is_array($settings) ? $settings : []),
            'status'      => (int)$page['status'],
            'viewCount'   => (int)$page['view_count'],
            'shareUrl'    => PageUtil::shareUrl($page['slug']),
            'createdAt'   => $page['created_at'],
            'updatedAt'   => $page['updated_at'],
        ]);
    }

    /**
     * 新建页面
     */
    public function save()
    {
        $data = $this->input();
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100) {
            return $this->fail('标题必填且不超过 100 字');
        }

        [$src, $type] = self::readContent($data);
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $settings['cover'] = (string)($data['cover'] ?? ($settings['cover'] ?? ''));

        Db::startTrans();
        try {
            $slug = PageUtil::genSlug();
            $id = (int)Db::name('pages')->insertGetId([
                'user_id'       => $this->uid(),
                'title'         => $title,
                'description'   => mb_substr(trim((string)($data['description'] ?? '')), 0, 500),
                'slug'          => $slug,
                'cover'         => mb_substr((string)$settings['cover'], 0, 500),
                'content_type'  => $type,
                'content_src'   => $src,
                'content_html'  => PageUtil::renderContent($src, $type),
                'settings_json' => json_encode(PageUtil::normalizeSettings($settings), JSON_UNESCAPED_UNICODE),
                'status'        => 0,
                'view_count'    => 0,
                'created_at'    => $this->now(),
                'updated_at'    => $this->now(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('保存失败');
        }

        OpLog::write('page', '创建页面', $title . '（#' . $id . '）');
        return $this->ok(['id' => $id, 'slug' => $slug, 'shareUrl' => PageUtil::shareUrl($slug)], '创建成功');
    }

    /**
     * 更新页面
     */
    public function update(int $id)
    {
        $page = $this->loadOwnedPage($id);
        if (!$page) {
            return $this->fail('页面不存在', 404);
        }
        $data   = $this->input();
        $update = ['updated_at' => $this->now()];

        if (isset($data['title'])) {
            $title = trim((string)$data['title']);
            if ($title === '' || mb_strlen($title) > 100) {
                return $this->fail('标题必填且不超过 100 字');
            }
            $update['title'] = $title;
        }
        if (isset($data['description'])) {
            $update['description'] = mb_substr(trim((string)$data['description']), 0, 500);
        }
        if (isset($data['content']) || isset($data['contentType'])) {
            [$src, $type] = self::readContent([
                'content'     => $data['content'] ?? ($page['content_src'] ?? ''),
                'contentType' => $data['contentType'] ?? ($page['content_type'] ?? 1),
            ]);
            $update['content_type'] = $type;
            $update['content_src']  = $src;
            $update['content_html'] = PageUtil::renderContent($src, $type);
        }
        if (isset($data['settings']) || isset($data['cover'])) {
            $settings = json_decode((string)$page['settings_json'], true) ?: [];
            if (is_array($data['settings'] ?? null)) {
                $settings = $data['settings'];
            }
            $settings['cover'] = mb_substr((string)($data['cover'] ?? ($settings['cover'] ?? '')), 0, 500);
            $update['cover'] = $settings['cover'];
            $update['settings_json'] = json_encode(PageUtil::normalizeSettings($settings), JSON_UNESCAPED_UNICODE);
        }

        Db::name('pages')->where('id', $id)->update($update);
        OpLog::write('page', '保存页面', $page['title'] . '（#' . $id . '）');
        return $this->ok([], '已保存');
    }

    /**
     * 发布 / 下线
     */
    public function status(int $id)
    {
        $page = $this->loadOwnedPage($id);
        if (!$page) {
            return $this->fail('页面不存在', 404);
        }
        $status = (int)($this->input()['status'] ?? 0);
        if (!in_array($status, [1, 2], true)) {
            return $this->fail('状态值不合法');
        }
        // 发布前检查内容非空，否则访客打开是一片空白
        if ($status === 1 && trim((string)$page['content_html']) === '') {
            return $this->fail('页面还没有内容，请先编辑');
        }

        Db::name('pages')->where('id', $id)->update(['status' => $status, 'updated_at' => $this->now()]);
        OpLog::write('page', $status === 1 ? '发布页面' : '下线页面', $page['title'] . '（#' . $id . '）');
        return $this->ok(['status' => $status], $status === 1 ? '页面已发布' : '页面已下线');
    }

    /**
     * 删除（进回收站）
     */
    public function delete(int $id)
    {
        $page = $this->loadOwnedPage($id);
        if (!$page) {
            return $this->fail('页面不存在', 404);
        }
        Db::name('pages')->where('id', $id)->update(['deleted_at' => $this->now(), 'status' => 2]);
        OpLog::write('page', '删除页面', $page['title'] . '（#' . $id . '）');
        return $this->ok([], '已删除');
    }

    /**
     * 回收站恢复
     */
    public function restore(int $id)
    {
        $page = Db::name('pages')->whereNotNull('deleted_at')->where('id', $id)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            ->find();
        if (!$page) {
            return $this->fail('回收站中不存在该页面', 404);
        }
        Db::name('pages')->where('id', $id)->update([
            'deleted_at' => null, 'status' => 0, 'updated_at' => $this->now(),
        ]);
        OpLog::write('page', '回收站恢复', $page['title'] . '（#' . $id . '）');
        return $this->ok([], '已恢复为草稿');
    }

    /**
     * 彻底删除
     */
    public function purge(int $id)
    {
        $page = Db::name('pages')->where('id', $id)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            ->find();
        if (!$page) {
            return $this->fail('页面不存在', 404);
        }
        Db::name('pages')->where('id', $id)->delete();
        OpLog::write('page', '彻底删除页面', $page['title'] . '（#' . $id . '）');
        return $this->ok([], '页面已彻底删除');
    }

    // ---------- 内部方法 ----------

    /**
     * 页面查询基（成员仅可见自己创建的）
     *
     * @access private
     */
    private function pageQuery()
    {
        $q = Db::name('pages')->whereNull('deleted_at');
        if (!$this->isAdmin()) {
            $q->where('user_id', $this->uid());
        }
        return $q;
    }

    /**
     * 载入有权限操作的页面
     *
     * @access private
     */
    private function loadOwnedPage(int $id): ?array
    {
        return $this->pageQuery()->where('id', $id)->find() ?: null;
    }

    /**
     * AI 生成页面内容
     *
     * 复用系统设置里的 AI 助理配置（ai_mode=custom 时的 OpenAI 兼容接口），
     * 非流式调用 —— 编辑器场景是一次性取回整段 HTML 插入正文，
     * 流式反而需要前端做增量 DOM 拼装，复杂且无收益。
     *
     * 生成的 HTML 只作为草稿素材：入库/展示仍走统一消毒，
     * AI 输出里的脚本类内容不会存活到访客端。
     */
    public function ai()
    {
        $baseUrl = trim((string)Setting::get('ai_base_url'));
        $model   = trim((string)Setting::get('ai_model'));
        $key     = trim((string)Setting::get('ai_key'));
        if (!Setting::get('ai_key') || !$baseUrl || !$model) {
            return $this->fail('AI 助理未启用自建模型，请在系统设置-AI 助理中完成配置', 4003);
        }
        // 与 AiChat 相同的出站校验：带密钥的请求不能指向内网
        if (!\app\controller\AiChat::isSafeUpstream($baseUrl)) {
            return $this->fail('AI 接口地址配置不合法：仅支持 https 公网地址', 4003);
        }

        $data    = $this->input();
        $prompt  = trim((string)($data['prompt'] ?? ''));
        $current = trim((string)($data['current'] ?? ''));
        if ($prompt === '') {
            return $this->fail('请描述你想要生成的页面内容');
        }
        if (mb_strlen($prompt) > 2000 || mb_strlen($current) > 50000) {
            return $this->fail('输入内容过长');
        }

        $messages = [
            ['role' => 'system', 'content' => self::aiSystemPrompt()],
        ];
        $userContent = $prompt;
        if ($current !== '') {
            $userContent .= "\n\n【当前页面已有内容（供参考或在其基础上修改）】\n" . $current;
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => min(2, max(0, (float)Setting::get('ai_temperature', '0.7'))),
        ];

        $res = self::callChatCompletions(rtrim($baseUrl, '/') . '/chat/completions', $key, $payload);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }

        $html = self::extractHtml($res['body']);
        if ($html === '') {
            return $this->fail('AI 未返回有效内容，请调整描述后重试');
        }
        // 输出前消毒：AI 可能产出脚本类内容，绝不原样回传
        $html = \app\logic\HtmlSanitizer::clean($html);

        return $this->ok(['html' => $html], '已生成');
    }

    /**
     * AI 系统提示词：页面内容生成器
     *
     * @access private
     */
    private static function aiSystemPrompt(): string
    {
        return <<<PROMPT
你是 OwnForm 页面编辑器的 HTML 内容生成器。根据用户的描述生成页面正文的 HTML 片段。

【输出要求（严格遵守）】
1. 只输出 HTML 片段本身：可使用 h1-h3、p、ul/ol/li、strong/em、img、a、table、blockquote、hr、div/span 等常规标签
2. 不要输出 <!DOCTYPE>、<html>、<head>、<body> 等文档级标签
3. 不要输出 <script>、<style>、<iframe>，不要使用 style 内联样式与 on* 事件属性（保存时会被安全过滤移除）
4. 不要用 Markdown 代码块围栏（三反引号）包裹输出
5. 内容为中文；排版简洁清晰；用多个段落与标题组织结构
6. 如需嵌入表单，输出独立一行的占位符 {{form:表单分享码}}；用户未给出分享码时不要臆造
PROMPT;
    }

    /**
     * 调用 OpenAI 兼容的 chat/completions（非流式）
     *
     * @return array{ok:bool, body:string, msg:string}
     */
    private static function callChatCompletions(string $url, string $key, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => '', 'msg' => 'AI 接口请求失败：' . $err];
        }
        if ($code !== 200) {
            return ['ok' => false, 'body' => (string)$body, 'msg' => 'AI 接口返回异常（HTTP ' . $code . '），请检查模型配置'];
        }
        return ['ok' => true, 'body' => (string)$body, 'msg' => ''];
    }

    /**
     * 从 chat/completions 响应中取出正文，并剥离可能的代码块围栏
     *
     * @access private
     */
    private static function extractHtml(string $body): string
    {
        $data = json_decode($body, true);
        $text = (string)($data['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            return '';
        }
        // 剥离 ```html ... ``` 围栏（模型常无视禁令加围栏）
        if (preg_match('/```(?:html)?\s*([\s\S]*?)```/i', $text, $m)) {
            $text = $m[1];
        }
        return trim($text);
    }

    /**
     * 读取并规范化内容
     *
     * @return array{0:string,1:int} [源码, 类型]
     */
    private static function readContent(array $data): array
    {
        $src  = (string)($data['content'] ?? '');
        $type = (int)($data['contentType'] ?? 1);
        if (!in_array($type, [PageUtil::CONTENT_HTML, PageUtil::CONTENT_MARKDOWN], true)) {
            $type = PageUtil::CONTENT_HTML;
        }
        // 限制单页内容体积，避免超大页面拖慢渲染
        return [mb_substr($src, 0, 500000, 'UTF-8'), $type];
    }

    /**
     * LIKE 通配符转义
     */
    private static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}