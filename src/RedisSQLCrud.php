<?php

namespace Morbihanet\RedisSQL;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class RedisSQLCrud
{
    const DS = DIRECTORY_SEPARATOR;
    public $user;
    public $action;

    public function __construct()
    {
        View::share('self', $this);
    }

    public function router(?string $path = null)
    {
        $parts = explode('/', $path);
        $last = end($parts);
        $last = empty($last) ? 'home' : $last;

        if (!in_array($last, ['login', 'logout', 'assets'])) {
            if (!$this->auth()) {
                return redirect()->route('redis-sql-admin.crud', ['login']);
            }
        }

        try {
            $this->action = $last;
            $method = Str::camel('do_' . $last);

            return $this->{$method}(...$parts);
        } catch (\Exception $t) {
            dd($t);
            return $this->page404();
        }
    }

    public function doAddRecord(): string
    {
        $table = $this->tables()->find($id = request()->get('id'));

        if (empty($table)) {
            return $this->showError();
        }

        if (RedisSQLUtils::isPost()) {
            $MAX_FILE_SIZE = Arr::get($_POST, 'MAX_FILE_SIZE');

            if (!is_null($MAX_FILE_SIZE)) {
                unset($_POST['MAX_FILE_SIZE']);
            }

            $db = $this->dbTable($table->getName());
            $record = $db;
            $this->makeUpload($record, $table, request());

            $record->hydrate();

            $this->redirect($this->r('displayData', compact('id')));
        }

        $html = $this->headerTable($id);
        $html .= '<p class="first">Add record</p>';

        $structures = $this->structures()->where('table_id', $id)->map(function ($s) {
            $field = $this->fields()->find($s->field_id);
            $s->name = $field->name;

            return $s;
        })->sortBy('name');

        $html .= '<form action="' . $this->r('addRecord', compact('id')) . '" method="post" id="addRecord" enctype="multipart/form-data">
            <input type="hidden" name="MAX_FILE_SIZE" value="100000000" />
            <table class="table">';

        foreach ($structures as $structure) {
            $field  = $structure->getName();

            if (in_array($field, ['created_at', 'updated_at'])) {
                continue;
            }

            $type       = $structure->getType();
            $label      = $structure->getLabel();
            $label      = !strlen($label) ? $field : $label;
            $length     = $structure->getLength();
            $required   = $structure->getCanBeNull() ? 'false' === $structure->getCanBeNull() : true;

            $input      = $this->inputForm($type, $field, $length, $required, $structure->getDefault(), $structure);
            $html .= '<tr>
                <th>' . $label . '</th>
                <td>' . $input . '</td>
                </tr>';
        }

        $html .= '<tr><td>&nbsp;</td>
        <td>
        <button type="submit">OK</button>
        <a href="' . $this->r('displayData', compact('id')) . '" class="btn btn-warning">Cancel</a>
        </td>
        </tr>';
        $html .= '</table></form>';
        $content = $html;
        $h1 = 'Add record in &laquo; <span class="yellowText">' . $table->getName() . '</span> &raquo;';
        $title = 'Add record in ' . $table->getName();

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    public function doEditRecord()
    {
        $request = request();
        $table = $this->tables()->find($idTable = $request->get('table'));
        $title = 'Edit record in ' . $table->getName();

        if (!$table) {
            return $this->showError();
        }

        $structures = $this->structures()->where('table_id', $idTable)->map(function ($s) {
            $field = $this->fields()->find($s->field_id);
            $s->name = $field->name;

            return $s;
        })->sortBy('name');

        $db = $this->dbTable($table->getName());
        $record = $db->find($request->get('id'));

        if (empty($record)) {
            return $this->showError();
        }

        if (RedisSQLUtils::isPost()) {
            unset($_POST['MAX_FILE_SIZE']);

            $this->makeUpload($record, $table, $request);
            $record->hydrate();

            $this->redirect($this->r('displayData', ['id' => $idTable]));
        }

        $html = $this->headerTable($table->getId());
        $html .= '<p class="first">Edit record ' . $record->getId() . '</p>';

        $urlForm = $this->r('editRecord', ['table' => $idTable, 'id' => $record->getId()]);

        $html .= '<form action="' . $urlForm . '" method="post" id="editRecord" enctype="multipart/form-data">
            <input type="hidden" name="MAX_FILE_SIZE" value="100000000" />
            <table class="table">';
        $html .= '<tr><th>id</th><td>' . $record->getId() . '</td></tr>';

        foreach ($structures as $structure) {
            $field  = $structure->getName();

            if (in_array($field, ['created_at', 'updated_at'])) {
                continue;
            }

            $label  = $structure->getLabel();
            $label = !strlen($label) ? $field : $label;
            $type = $structure->getType();
            $length = $structure->getLength();
            $required  = $structure->getCanBeNull() ? false : true;

            $input = $this->inputForm($type, $field, $length, $required, $record->$field, $structure);
            $html .= '<tr>
                <th>' . $label . '</th>
                <td>' . $input . '</td>
                </tr>';
        }

        $html .= '<tr><td>&nbsp;</td><td>
        <button onclick="$(\'#editRecord\').submit();">OK</button>
        <a href="' . $this->r('displayData', ['id' => $idTable]) . '" class="btn btn-warning">Cancel</a>
        </td>
        </tr>';
        $html .= '</table></form>';

        $content = $html;

        $h1 = 'Edit record in &laquo; <span class="yellowText">' . $table->getName() . '</span> &raquo;';

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    public function doDeleteRecord()
    {
        $request = request();
        $id = $request->get('id');
        $table = $request->get('table');

        if (strlen($id) && strlen($table)) {
            $token = sha1('deleteRecord' . $id . $table . date('dmY'));
            $url = $this->r('deleteRecordConfirm', compact('table', 'id', 'token'));
            $display = $this->r('displayData', ['id' => $table]);
            $t = $this->tables()->find($table);

            $h1 = 'Delete record from &laquo; <span class="yellowText">' . $t->getName() . '</span> &raquo;';

            $content = '<p class="alert alert-warning">
                Click <a href="' . $url . '">here</a> to confirm this deletion or <a href="' . $display . '">here</a> to go back.
                </p>';

            return view('rsql::content', compact('content', 'h1'));
        }

        return $this->showError();
    }

    public function doDeleteRecordConfirm()
    {
        $request = request();
        $id     = $request->get('id');
        $table  = $request->get('table');
        $token  = $request->get('token');

        if (strlen($id) && strlen($table) && strlen($token)) {
            if ($token === sha1('deleteRecord' . $id . $table . date('dmY'))) {
                $db = $this->dbTable($this->tables()->find($table)->getName());
                $obj = $db->find($id);

                if ($obj) {
                    $obj->delete();
                    $this->redirect($this->r('displayData', ['id' => $table]));
                }
            } else {
                return $this->showError('Wrong token.');
            }
        }

        return $this->showError();
    }

    private function inputForm($type, $field, $length, $required, $value, $structure)
    {
        $type = strstr($type, 'text') ? 'text' : $type;
        $require = $required ? 'required' : '';

        if (strstr($type, 'fk_')) {
            $table = str_replace('fk_', '', $type);
            $datas = $this->dbTable($table)->where('id', '>', 0);

            $tableFk = $this->tableByValue($table);
            $select = '<select class="input-medium" ' . $require . ' id="' . $field . '" name="' . $field . '">';

            if (!empty($datas)) {
                foreach ($datas as $row) {
                    $display = $row->getForeignLabel() ?? $row->getLabel()
                        ?? $row->getTitle() ?? $row->getName() ?? $row->getId();

                    $selected = $value == $row->id ? 'selected' : '';
                    $select .= '<option ' . $selected . ' value="' . $row->id . '">' . $display . '</option>';
                }
            }

            $select .= '</select>
                <a target="_plus" href="' . $this->r('addRecord', ['id' => $tableFk->getId()]) . '">
                <i rel="tooltip" title="Add ' . $table . '" class="fa fa-plus"></i>
                </a>';

            $type = 'fk';
        }

        switch ($type) {
            case 'set':
                $sets = $structure->getDefault();

                if (strlen($sets)) {
                    $tab = explode(',', $sets);
                    $return = '<select class="input-medium" ' . $require . ' id="' . $field . '" name="' . $field . '">';

                    foreach ($tab as $k => $row) {
                        if (strstr($row, '%%')) {
                            [$k, $v] = explode('%%', $row, 2);
                        } else {
                            $v = $row;
                        }

                        $selected = $value === $k ? 'selected' : '';
                        $return .= '<option ' . $selected . ' value="' . $k . '">' . $v . '</option>';
                    }

                    $return .= '</select>';

                    return $return;
                } else {
                    return '<input ' . $require . ' maxlength="' . $length . '" class="input-medium" autocomplete="off" name="' . $field . '" id="' . $field . '" value="' . $value . '">';
                }
            case 'file':
                $input = '<input ' . $require . ' maxlength="' . $length . '" class="input-medium" name="' . $field . '" id="' . $field . '" type="file">';

                if (!is_null($value)) {
                    $name       = Arr::last(explode('/', $value));
                    $isImage    = false;

                    if (strstr($name, '.')) {
                        $isImage = $this->isImage($name);
                    }

                    if (true === $isImage) {
                        $file = '<img rel="tooltip" title="download" style="height: 150px; width: 150px;" src="' . $value . '" alt="' . $field . '">';
                    } else {
                        $file = $name;
                    }

                    $input .= '<p><a href="' . $value . '" target="_blank">' . $file . '</a></p>';

                    return $input;
                }

                break;
            case 'fk':
                return $select;
            case 'text':
                return '<textarea ' . $require . ' maxlength="' . $length . '" class="input-medium" name="' . $field . '" id="' . $field . '">' . $value . '</textarea>';
            case 'wysiwyg':
                return '<textarea ' . $require . ' class="iswysiwyg" name="' . $field . '" id="' . $field . '">' . $value . '</textarea>';
            case 'email':
                return '<input type="email" ' . $require . ' maxlength="' . $length . '" class="input-medium" autocomplete="off" name="' . $field . '" id="' . $field . '" value="' . $value . '">';
            default:
                return '<input ' . $require . ' maxlength="' . $length . '" class="input-medium" name="' . $field . '" autocomplete="off" id="' . $field . '" value="' . $value . '">';
        }
    }

    private function makeUpload($record, $table, Request $request)
    {
        $structures = $this->structures()->where('table_id', $table->getId());
        $files = [];

        foreach ($structures as $structure) {
            $type = $structure->getType();

            if ($type === 'file') {
                $field = $this->fields()->find($structure->field_id)->getName();
                array_push($files, $field);
            }
        }

        if (!empty($files)) {
            foreach ($files as $file) {
                $record->{$field} = $this->upload($field, $table->getName());
            }
        }

        return $record;
    }

    private function upload($field, $table)
    {
        return request()->file($field)->store("$table/$field");
    }

    public function page404()
    {
        return view('rsql::page404', ['title' => 'Page not founds', 'is_auth' => $this->auth()]);
    }

    public function doAssets()
    {
        $path = urldecode(request('asset'));
        $path = __DIR__ . static::DS . 'resources' . static::DS . 'assets' . static::DS . $path;

        if (file_exists($path)) {
            return response()->file($path, [
                'Content-Type' => RedisSQLUtils::getMimeType($path),
            ]);
        }

        return response('Not found', 404);
    }

    public function doHome()
    {
        return view('rsql::home');
    }

    public function doAddTable()
    {
        if (RedisSQLUtils::isPost()) {
            $name = request()->get('name');

            $table = $this->tables()->firstOrCreate(compact('name'));

            $field = $this->fields()->firstOrCreate(['name' => 'foreign_label']);
            $s = $this->structures()->firstOrCreate(['table_id' => $table->getId(), 'field_id' => $field->getId()]);

            $s->update([
                'type' => 'varchar',
                'length' => 255,
                'default' => '',
                'can_be_null' => true,
                'is_index' => 'false',
                'label' => 'foreign_label',
            ]);

            $field = $this->fields()->firstOrCreate(['name' => 'created_at']);
            $s = $this->structures()->firstOrCreate(['table_id' => $table->getId(), 'field_id' => $field->getId()]);

            $s->update([
                'type' => 'timestamp',
                'length' => 30,
                'default' => '',
                'can_be_null' => false,
                'is_index' => 'false',
                'label' => 'created_at',
            ]);

            $field = $this->fields()->firstOrCreate(['name' => 'updated_at']);
            $s = $this->structures()->firstOrCreate(['table_id' => $table->getId(), 'field_id' => $field->getId()]);

            $s->update([
                'type' => 'timestamp',
                'length' => 30,
                'default' => '',
                'can_be_null' => false,
                'is_index' => 'false',
                'label' => 'updated_at',
            ]);

            return redirect()->route('redis-sql-admin.crud', [
                'path' => 'table',
                'id' => $table->getId(),
            ]);
        }

        return view('rsql::addTable');
    }

    public function doTable()
    {
        return view('rsql::table');
    }

    public function doLogin()
    {
        $error = null;

        if (RedisSQLUtils::isPost()) {
            $data = $this->validateLogin(request());
            $user = $this->repo('auth')->where('username', $data['username'])->first();

            if ($user && password_verify($data['password'], $user->password)) {
                $user->token = RedisSQLUtils::bearer('rsql_crud');
                $user->save();
                session(['redissqlauth' => $user->id]);

                return redirect()->route('redis-sql-admin.home');
            } else {
                $error = 'Invalid credentials';
            }
        }

        return view('rsql::login', [
            'is_auth' => $this->auth(),
            'error' => $error,
        ]);
    }

    protected function auth(): bool
    {
        if (!(session('redissqlauth') ?? false)) {
            $auth = $this->repo('auth');

            if ($auth->isEmpty()) {
                $auth->firstOrCreate([
                    'username' => 'admin',
                    'password' => password_hash('admin', PASSWORD_DEFAULT),
                ]);
            } else {
                if ($user = $auth->where('token', RedisSQLUtils::bearer('rsql_crud'))->first()) {
                    session(['redissqlauth' => $user->id]);

                    View::share('authuser', $this->user = $user);

                    return true;
                }
            }

            return false;
        }

        $user = $this->repo('auth')->find(session()->get('redissqlauth'));

        View::share('authuser', $this->user = $user);

        return true;
    }

    public function doLogout()
    {
        $session = session();

        if ($user = $this->repo('auth')->find($session->get('redissqlauth') ?? 0)) {
            $user->token = null;
            $user->save();
        }

        $session->forget('redissqlauth');
        $session->invalidate();
        $session->regenerateToken();

        $this->redirect($this->r('login'));
    }

    protected function validateLogin(Request $request): array
    {
        return $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
    }

    public function structures(): RedisSQL
    {
        return $this->repo('structure');
    }

    public function tables(): RedisSQL
    {
        return $this->repo('table');
    }

    public function fields(): RedisSQL
    {
        return $this->repo('field');
    }

    public function repo(string $repo): RedisSQL
    {
        $key = 'rsql' . Str::lower($repo);

        return RedisSQL::forTable($key);
    }

    public function truncate(?string $str, int $length = 20): ?string
    {
        if (strlen($str) > $length) {
            $str = substr($str, 0, $length) . '&hellip;';
        }

        return $str;
    }

    public function emptyCollection(): RedisSQLCollection
    {
        return new RedisSQLCollection;
    }

    public function table(): string
    {
        $html = $this->headerTable($id = request()->get('id'));
        $table = $this->tables()->findOrFail($id);

        $html .= '<table class="table">';
        $html .= '<tr>';
        $html .= '<th>Field</th>';
        $html .= '<th>Type</th>';
        $html .= '<th>Default</th>';
        $html .= '<th>Index</th>';
        $html .= '<th>Null</th>';
        $html .= '<th>&nbsp;</th>';
        $html .= '</tr>';

        $html .= '<tr>';
        $html .= '<td>id</td>';
        $html .= '<td>int(11)</td>';
        $html .= '<td>&nbsp;</td>';
        $html .= '<td class="true"><i class="fa fa-check"></td>';
        $html .= '<td class="false"><i class="fa fa-ban"></td>';
        $html .= '<td>&nbsp;</td>';
        $html .= '</tr>';

        $timestamps = [];

        $structures = $this->structures()->where('table_id', $id)->map(function ($s) {
            $field = $this->fields()->find($s->field_id);
            $s->name = $field->name;

            return $s;
        })->sortBy('name');

        /** @var RedisSQL $s */
        foreach ($structures as $s) {
            if (in_array($s->name, ['created_at', 'updated_at'])) {
                $timestamps[$s->name] = $s;
                continue;
            }

            if ($s->name === 'foreign_label') {
                continue;
            }

            $html .= '<tr>';
            $html .= '<td>' . $s->name . '</td>';
            $html .= '<td>' . $s->type . '(' . $s->length . ')</td>';
            $html .= '<td>' . $this->truncate($s->default) . '</td>';

            if (true === $s->is_index) {
                $html .= '<td class="true"><i class="fa fa-check"></td>';
            } else {
                $html .= '<td class="false"><i class="fa fa-ban"></td>';
            }

            if (true === $s->can_be_null) {
                $html .= '<td class="true"><i class="fa fa-check"></td>';
            } else {
                $html .= '<td class="false"><i class="fa fa-ban"></td>';
            }

            if (in_array($s->name, ['created_at', 'updated_at', 'foreign_label'])) {
                $html .= '<td>&nbsp;</td>';
            } else {
                $html .= '<td>
                    <a href="' . go('edit_structure', ['id' => $s->getId()]) . '"><i rel="tooltip" title="Edit structure" class="fa fa-edit"></i></a> |
                    <a href="' . go('delete_structure', ['id' => $s->getId()]) . '"><i rel="tooltip" title="Delete structure" class="fa fa-trash-o"></i></a>
                </td>';
            }

            $html .= '</tr>';
        }

        foreach ($timestamps as $s) {
            $html .= '<tr>';
            $html .= '<td>' . $s->name . '</td>';
            $html .= '<td>' . $s->type . '(' . $s->length . ')</td>';
            $html .= '<td>' . $this->truncate($s->default) . '</td>';

            if (true === $s->is_index) {
                $html .= '<td class="true"><i class="fa fa-check"></td>';
            } else {
                $html .= '<td class="false"><i class="fa fa-ban"></td>';
            }

            if (true === $s->can_be_null) {
                $html .= '<td class="true"><i class="fa fa-check"></td>';
            } else {
                $html .= '<td class="false"><i class="fa fa-ban"></td>';
            }

            $html .= '<td>&nbsp;</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        $otherTables = $this->tables()->where('id', '!=', $id)->sortBy('name');
        $hasManies = $table->getHasMany([]);

        $html .= '<h3 class="mt-1">Has many</h3>';
        $html .= '<div class="row mb-2">';

        foreach ($otherTables as $t) {
            $inHasManies = in_array($t->getName(), $hasManies);
            $html .= '<div class="col-md-6">' . $t->getName() . '</div>';
            $html .= '<div class="col-md-6">';
            $html .= $inHasManies
                ? '<input data-action="'.$this->r('remove_has_many', ['table' => $table->getId(), 'fk' => $t->getId()])
                .'" class="hasmany" type="checkbox" checked id="hm_'.$table->getId()
                .'_'
                .$t->getId()
                .'" value="' .
                $t->getName() . '">'
                : '<input data-action="'.$this->r('add_has_many', ['table' => $table->getId(), 'fk' => $t->getId()])
                .'" class="hasmany" type="checkbox" id="hm_'.$table->getId()
                .'_'
                .$t->getId()
                .'" value="' .
                $t->getName() . '">';

            $html .= '</div>';
        }

        $html .= '</div>';

        $html .= '<p><a href="' . go('add_structure', ['table' => $id]) . '"><i class="fa fa-plus"></i> Add a field</a></p>';

        return $html;
    }

    public function doAddHasMany()
    {
        $table = $this->tables()->findOrFail(request()->get('table'));
        $fk = $this->tables()->findOrFail(request()->get('fk'));

        if ($table && $fk) {
            $hasManies = $table->getHasMany([]);
            $hasManies[] = $fk->getName();
            $table->setHasMany(array_unique($hasManies));
            $table->save();
        }

        return response()->json(['success' => true]);
    }

    public function doRemoveHasMany()
    {
        $table = $this->tables()->findOrFail(request()->get('table'));
        $fk = $this->tables()->findOrFail(request()->get('fk'));

        if ($table && $fk) {
            $hasManies = $table->getHasMany([]);

            $hasManies = array_filter($hasManies, function ($t) use ($fk) {
                return $t !== $fk->getName();
            });

            $table->setHasMany(array_unique($hasManies));
            $table->save();
        }

        return response()->json(['success' => true]);
    }

    public function r(...$args): string
    {
        return go(...$args);
    }

    public function doEmptyTable()
    {
        $request = request();
        $id = $request->get('id');

        if (strlen($id)) {
            if ($table  = $this->tables()->find($id)) {
                $token = sha1('emptyTable' . $id . date('dmY'));

                $confirm = $this->r('emptyTableConfirm', compact('id', 'token'));
                $display = $this->r('displayData', compact('id'));
                $h1 = 'Empty table &laquo; <span class="yellowText">' . $table->getName() . '</span> &raquo;';

                $content = '<p class="alert alert-warning">
                        Click <a href="' . $confirm . '">here</a> to empty the table &laquo; ' . $table->getName() . ' &raquo; or <a href="' . $display . '">here</a> to go back.</p>';

                return view('rsql::content', compact('content', 'h1'));
            } else {
                return $this->showError();
            }
        } else {
            return $this->showError();
        }
    }

    public function doEmptyTableConfirm(): string
    {
        $request = request();
        $id = $request->get('id');
        $token = $request->get('token');

        if (strlen($id) && strlen($token)) {
            $table = $this->tables()->find($id);

            if (!empty($table)) {
                if ($token === sha1('emptyTable' . $id . date('dmY'))) {
                    $this->dbTable($table->getName())->drop();
                    $this->redirect($this->r('displayData', compact('id')));
                }
            }
        }

        return $this->showError();
    }

    public function doRemoveTableConfirm()
    {
        $content = $this->error('Wrong request.');
        $h1 = 'Error happened.';
        $request = request();
        $id = $request->get('id');
        $token = $request->get('token');

        if (strlen($id) && strlen($token)) {
            if ($table = $this->tables()->find($id)) {
                if ($token === sha1('removeTable' . $id . date('dmY'))) {
                    $db = $this->dbTable($table->getName());

                    $db->drop();

                    $this->structures()->where('table_id', $id)->delete();

                    $table->delete();

                    return redirect()->route('redis-sql-admin.crud', ['path' => 'home']);
                }
            }
        }

        return view('rsql::content', compact('content', 'h1'));
    }

    public function dbTable(string $name): RedisSQL
    {
        return RedisSQL::forTable($name);
    }

    public function doRemoveTable()
    {
        $content = $this->error('Wrong request.');
        $h1 = 'Error happened.';

        if ($id  = request()->get('id')) {
            if ($table  = $this->tables()->find($id)) {
                $token = sha1('removeTable' . $id . date('dmY'));

                $confirm = $this->r('removeTableConfirm', compact('id', 'token'));
                $display = $this->r('displayData', compact('id'));

                $h1 = 'Remove table &laquo; <span class="yellowText">' . $table->getName() . '</span> &raquo;';

                $content = '<div class="alert alert-warning">
                    Click <a href="' . $confirm . '">here</a> to remove the table &laquo; ' . $table->getName() . ' &raquo; or <a href="' . $display . '">here</a> to go back.
                    </div>';
            } else {
                $content = $this->error('Wrong request.');
            }
        }

        return view('rsql::content', compact('content', 'h1'));
    }

    public function error(string $message): string
    {
        return '<div class="alert alert-danger mb-3 mt-3">' . $message . '</div>';
    }

    public function doQuery()
    {
        $table = $this->tables()->find(request()->get('table_id'));
        $h1 = 'Query on &laquo; <span class="yellowText">' . $table->getName() . '</span> &raquo;';
        $title = 'Query on ' . $table->getName();

        if (RedisSQLUtils::isPost()) {
            $query = 'return ' . request()->get('query') . ';';
            $db = $this->dbTable($table->getName());
            $datas = eval($query);

            $html = $this->headerTable($table->getId());
            $html .= '<p class="first">Query : <code>' . $query . '</code></p>';
            $html .= '<table class="table">';
            $html .= '<tr>';
            $html .= '<th>id</th>';

            foreach ($datas as $data) {
                foreach ($data->toArray() as $k => $v) {
                    if (in_array($k, ['id', 'foreign_label']) or str_starts_with($k, '_')) {
                        continue;
                    }

                    $html .= '<th>' . $k . '</th>';
                }

                break;
            }

            $html .= '</tr>';

            foreach ($datas as $data) {
                $html .= '<tr>';
                $html .= '<td>' . $data->id . '</td>';

                foreach ($data->toArray() as $k => $v) {
                    if (in_array($k, ['id', 'foreign_label']) or str_starts_with($k, '_')) {
                        continue;
                    }

                    if (in_array($k, ['created_at', 'updated_at'])) {
                        $v = date('d/m/Y H:i:s', $v);
                    }

                    $html .= '<td>' . $v . '</td>';
                }

                $html .= '</tr>';
            }

            $html .= '</table>';

            return view('rsql::content', ['content' => $html, 'h1' => $h1]);
        }

        $content = $this->headerTable($table->getId());

        $content .= '<p class="first">
            <form action="' . $this->r('query', ['table_id' => $table->getId()]) . '" method="post">
            '.csrf_field().'
            <textarea class="input-medium" name="query" id="query"></textarea>
            <p><button type="submit">Query</button> <a href="' . $this->r('table', ['id' => $table->getId()]) . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Back</a></p>
            </form>';

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    public function headerTable(int $id): string
    {
        $html = '<h3 class="titleTable">' . $this->tables()->find($id)?->name . '</h3>';

        $first = $this->action !== 'display_data'
            ? '<a href="' . go('display_data', ['id' => $id]) . '"><i class="fa fa-folder-open"></i> Display data</a>'
            : '<a href="' . go('table', ['id' => $id]) . '"><i class="fa fa-tasks"></i> Display structures</a>';

        $html .= '<p>
                ' . $first . ' |
                <a href="' . go('add_record', ['id' => $id]) . '"><i class="fa fa-plus-square"></i> Add record</a> |
                <a href="' . go('query', ['table_id' => $id]) . '"><i class="fa fa-search"></i> Query</a> |
                <a href="' . go('import', ['table' => $id]) . '"><i class="fa fa-mail-forward"></i> Import data</a> |
                <a href="' . go('empty_table', ['id' => $id]) . '"><i class="fa fa-ban"></i> Empty the table</a> |
                <a href="' . go('remove_table', ['id' => $id]) . '"><i class="fa fa-trash-o"></i> Delete the table</a>
            </p>';

        return $html;
    }

    public function doAddStructure()
    {
        $content = $this->error('Wrong request.');
        $title = $h1 = 'Error happened.';

        if ($this->tables()->find($id = request()->get('table'))) {
            if (RedisSQLUtils::isPost()) {
                $this->checkBool('is_index')->checkBool('can_be_null');

                $name = request()->get('name');
                $field = $this->fields()->firstOrCreate(compact('name'));

                $s = $this->structures()->firstOrCreate([
                    'table_id' => $id,
                    'field_id' => $field->getId()
                ]);

                $s->update([
                    'type'          => request('type'),
                    'length'        => request('length'),
                    'default'       => request('default'),
                    'can_be_null'   => $_POST['can_be_null'],
                    'is_index'      => $_POST['is_index'],
                    'label'         => request('label'),
                ]);

                $this->redirect(go('table', ['id' => $id]));
            }

            $selectTypes = $this->selectTypes(null, $id);
            $title = $h1 = 'Add a new field';

            $content = $this->headerTable($id);

            $content .= '<p class="first">
                <form action="' . $this->r('addStructure', ['table' => $id]) . '" method="post" id="editStructure">
                '.csrf_field().'
                <table class="table">
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Length</th>
                    <th>Default</th>
                    <th>Null</th>
                    <th>Index</th>
                    <th>Label</th>
                </tr>
                <tr>
                    <td><input autocomplete="off" required class="input-small" name="name" value="" id="name" type="text"></td>
                    <td>' . $selectTypes . '</td>
                    <td><input class="input-small" autocomplete="off" value="255" name="length" id="length"></td>
                    <td><input class="input-small" autocomplete="off" value="" name="default" id="default"></td>
                    <td><input name="can_be_null" value="true" id="can_be_null" type="checkbox"></td>
                    <td><input name="is_index" value="true" id="is_index" type="checkbox"></td>
                    <td><input class="input-small" autocomplete="off" name="label" value="" id="label" type="text"></td>
                </tr>
                </table>
                </p>
                <p><button type="submit">Add</button> <a href="' . $this->r('table', ['id' => $id]) . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Back</a></p>
                </form>';
        }

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    public function doEditStructure(): string
    {
        $content = $this->error('Wrong request. <a href="' . $this->r('home') . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Go Home</a>');
        $h1 = 'Error happened.';

        if ($structure = $this->structures()->find($id = request()->get('id'))) {
            if (RedisSQLUtils::isPost()) {
                $this->checkBool('is_index')->checkBool('can_be_null');

                $structure->update([
                    'type' => request('type'),
                    'length' => request('length'),
                    'default' => request('default'),
                    'can_be_null' => $_POST['can_be_null'],
                    'is_index' => $_POST['is_index'],
                    'label' => request('label'),
                ]);

                $this->redirect(go('table', ['id' => $structure->getTableId()]));
            }

            $content = $this->headerTable($structure->getTableId());
            $canBeNull = true === $structure->getCanBeNull() ? 'checked' : '';
            $isIndex = true === $structure->getIsIndex() ? 'checked' : '';
            $selectTypes = $this->selectTypes($structure);
            $field = $this->fields()->find($structure->getFieldId());
            $h1 = 'Edit structure of &laquo; ' . $field->getName() . ' &raquo;';
            $content .= '<p class="first">
                <form action="' . $this->r('editStructure', compact('id')) . '" method="post" id="editStructure">
                '.csrf_field().'
                <table class="table">
                <tr>
                    <th>Type</th>
                    <th>Length</th>
                    <th>Default</th>
                    <th>Null</th>
                    <th>Index</th>
                    <th>Label</th>
                </tr>
                <tr>
                    <td>' . $selectTypes . '</td>
                    <td><input autocomplete="off" class="input-small" value="' . $structure->getLength() . '" name="length" id="length" /></td>
                    <td><input autocomplete="off" class="input-small" value="' . $structure->getDefault() . '" name="default" id="default" /></td>
                    <td><input name="can_be_null" value="true" id="can_be_null" type="checkbox" ' . $canBeNull . ' /></td>
                    <td><input name="is_index" value="true" id="is_index" type="checkbox" ' . $isIndex . '/></td>
                    <td><input autocomplete="off" class="input-small" value="' . $structure->getLabel() . '" name="label" id="label" /></td>
                </tr>
                </table>
                </p>
                <p>
                <button type="submit">EDIT</button> <a href="' . $this->r('table', ['id' => $structure->getTableId()]) . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Back</a>
                </p>
                </form>
                ';
        }

        return view('rsql::content', compact('content', 'h1'));
    }

    public function doDeleteStructure()
    {
        $content = $this->error('Wrong request. <a href="' . $this->r('home') . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Go Home</a>');
        $h1 = 'Error happened.';

        if ($id = request()->get('id')) {
            if ($obj = $this->structures()->find($id)) {
                $field = $this->fields()->find($obj->getFieldId());
                $key = sha1('deleteStructure' . $id . date('dmY'));

                $h1 = 'Delete structure &laquo; <span class="yellowText">' . $field->getName() . '</span> &raquo;';

                $content = '<p class="alert alert-warning">
                    Click <a href="' . $this->r('deleteStructureConfirm', ['token' => $key, 'id' => $id]) . '">here</a> to confirm this deletion or <a href="' . $this->r('table', ['id' => $obj->getTableId()]) . '">go back</a>.
                    </p>';
            }
        }

        return view('rsql::content', compact('content', 'h1'));
    }

    public function doDeleteStructureConfirm()
    {
        $id = request()->get('id');
        $token = request()->get('token');

        $content = $this->error('Wrong request. <a href="' . $this->r('home') . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Go Home</a>');
        $h1 = 'Error happened.';

        if (strlen($id) && strlen($token)) {
            if ($token === sha1('deleteStructure' . $id . date('dmY'))) {
                if ($obj = $this->structures()->find($id)) {
                    $obj->delete();

                    $this->redirect($this->r('table', ['id' => $obj->getTableId()]));
                } else {
                    $this->goHome();
                }
            }
        }

        return view('rsql::content', compact('content', 'h1'));
    }

    public function showError(string $message = 'Wrong request.')
    {
        $content = $this->error($message . ' <a href="' . $this->r('home') . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Go Home</a>');
        $h1 = 'Error happened.';
        $title = 'Error happened.';

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    public function pdf(RedisSQLCollection $collection, string $tableName): mixed
    {
        $data = $this->excel($collection, $tableName, true);
        $dbName = $data['dbName'];

        $file = "Export_" . $dbName . "_" . $tableName . "_" . date('d_m_Y_H_i_s') . ".pdf";

        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $html = '<style>td {border: solid 1px #000;} </style>' . $data['html'];
        $html = str_replace('border-collapse:collapse; table-layout: fixed;', 'padding: 1em;', $html);

        $pdf->loadHTML($html);

        return $pdf->download($file);
    }

    public function excel(RedisSQLCollection $collection, string $tableName, bool $data = false)
    {
        $dbName = 'RedisSQL';

        if ($collection->isEmpty()) {
            return false;
        }

        $datas = $collection->toArray();

        if (empty($datas)) {
            return false;
        }

        $fields = collect(array_keys(Arr::first($datas)->toArray()))->sort()->toArray();
        $excel = '<html lang="en">

            <head>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
                <title></title>
            </head>
            <body>
                <table style="border-collapse:collapse; table-layout: fixed;">
                 <tr>
                  ##headers##
                 </tr>
                 ##content##
                </table>
            </body>
        </html>';

        $tplHeader = '<td style="font-weight: bold; padding: 6px;">##value##</td>';
        $tplData = '<td>##value##</td>';

        $headers = ['id'];

        foreach ($fields as $field) {
            if (in_array($field, ['id', 'created_at', 'updated_at', 'foreign_label']) or str_starts_with($field, '_')) {
                continue;
            }

            $headers[] = static::display($field);
        }

        $headers[] = 'created_at';
        $headers[] = 'updated_at';

        $xlsHeader = '';

        foreach ($headers as $header) {
            $xlsHeader .= str_replace('##value##', $header, $tplHeader);
        }

        $excel = str_replace('##headers##', $xlsHeader, $excel);

        $xlsContent = '';

        foreach ($datas as $item) {
            $xlsContent .= '<tr>';

            foreach ($headers as $field) {
                if (str_starts_with($field, '_')) {
                    continue;
                }

                $value = Arr::get($item, $field, '&nbsp;');

                if (empty($value)) {
                    $value = '&nbsp;';
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }

                if (str_ends_with($field, '_at')) {
                    $value = date('d/m/Y H:i:s', (int) $value->getTimestamp());
                }

                $xlsContent .= str_replace('##value##', static::display($value), $tplData);
            }

            $xlsContent .= '</tr>';
        }

        $html = str_replace('##content##', $xlsContent, $excel);

        if ($data) {
            return compact('html', 'dbName', 'tableName');
        }

        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: private", false);
        header("Content-Type: application/vnd.ms-excel");

        header(
            "Content-Disposition: attachment; filename=\"Export_" . $dbName . "_" .
            $tableName . "_" . date('d_m_Y_H_i_s') . ".xlsx\";"
        );

        header("Content-Transfer-Encoding: binary");

        die($html);
    }

    public function doImport(): string
    {
        $request = request();
        $table  = $this->tables()->find($request->get('table'));

        if (empty($table)) {
            return $this->showError();
        }

        if (RedisSQLUtils::isPost()) {
            if (!$up = $this->uploadSession('csv')) {
                return $this->showError("An error occured. Please try again.");
            } else {
                $structures = $this->structures()->where('table_id', $table->getId())->map(function ($s) {
                    $field = $this->fields()->find($s->field_id);
                    $s->name = $field->name;

                    return $s;
                })->sortBy('name');

                return $this->map($structures, $table, $request->get('separator'));
            }
        }

        $u = $this->r('import', ['table' => $table->getId()]);

        $h1 = 'Import data in &laquo; <span class="yellowText">' . $table->getName() . '</span> &raquo;';
        $title = 'Import data in "' . $table->getName() . '"';

        $content = '<p class="first">
                <form action="' . $u . '" method="post" id="import" enctype="multipart/form-data">
                <input type="hidden" name="MAX_FILE_SIZE" value="100000000" />
                Choose a csv file <input type="file" id="csv" name="csv" />
                Choose a separator <select class="input-medium" id="separator" name="separator">
                    <option value="%%">%%</option>
                    <option value=";;">;;</option>
                    <option value="##">##</option>
                </select>
                <br>
                <button onclick="$(\'#editRecord\').submit();">OK</button>
                <a href="' . $this->r('table', ['id' => $table->getId()]) . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Back</a>
                </form>
            </p>';

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    protected function map(RedisSQLCollection $structures, RedisSQL $table, string $separator = '%%')
    {
        $session = session();
        $data = $session->get('rsql.csv');

        if (!strlen($data)) {
            return $this->showError("An error occured. Please try again.");
        }

        $rows   = explode("\n", $data);
        $first  = Arr::first($rows);

        $u = $this->r('importMap', ['table' => $table->getId()]);

        $html   = '<p><h2>Map the fields to structure</h2></p>
            <form action="' . $u . '" method="post" name="map" id="map"><input type="hidden" name="separator" id="separator" value="' . $separator . '" />
            ';

        $select = '<select id="field_##key##" name="field_##key##">';

        foreach ($structures as $structure) {
            $label = $structure->getLabel();
            $field = $this->fields()->find($structure->getFieldId());
            $label = !strlen($label) ? $field->getName() : $label;
            $select .= '<option value="' . $field->getName() . '">' . $label . '</option>';
        }

        $select .= '</select>';

        if (!strstr($first, $separator)) {
            return $this->showError('An error occured. Plese try again.');
        }

        $words = explode($separator, $first);

        for ($i = 0; $i < count($words); ++$i) {
            $word = trim($words[$i]);
            $tmpSelect = str_replace('##key##', $i, $select);
            $html .= $word . ' => ' . $tmpSelect . '<br />';
        }

        $html .= '<button onclick="$(\'#map\').submit();">OK</button>
                </form>';

        $h1 = 'Map the fields to structure';
        $content = $html;

        return view('rsql::content', compact('content', 'h1'));
    }

    protected function uploadSession(string $field): bool
    {
        $session = session();

        $upload  = Arr::get($_FILES, $field);

        if (!empty($upload)) {
            $session->put('rsql.' . $field, file_get_contents($upload['tmp_name']));
            $session->save();

            return true;
        }

        return false;
    }

    public static function display(string $str): string
    {
        return stripslashes(static::utf8($str));
    }

    public static function utf8(string $str): string
    {
        if (false === static::isUtf8($str)) {
            $str = utf8_encode($str);
        }

        return $str;
    }

    public static function isUtf8($string): bool
    {
        if (!is_string($string)) {
            return false;
        }

        return !strlen(
            preg_replace(
                ',[\x09\x0A\x0D\x20-\x7E]'
                . '|[\xC2-\xDF][\x80-\xBF]'
                . '|\xE0[\xA0-\xBF][\x80-\xBF]'
                . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
                . '|\xED[\x80-\x9F][\x80-\xBF]'
                . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
                . '|[\xF1-\xF3][\x80-\xBF]{3}'
                . '|\xF4[\x80-\x8F][\x80-\xBF]{2}'
                . ',sS',
                '',
                $string
            )
        );
    }

    public function doDisplayData()
    {
        $request = request();
        $tables = $this->tables();
        $structures = $this->structures();
        $fieldDb = $this->fields();
        $ids = request()->get('ids');

        if (!$t = $tables->find($id = $request->get('id'))) {
            return $this->showError("Please choose a valid table.");
        }

        /** @var string[] $hasManies */
        $hasManies = $t->getHasMany([]);

        $h1 = 'Display data of &laquo; <span class="yellowText">' . $t->getName() . '</span> &raquo;';
        $title = 'Display data of « ' . $t->getName() . ' »';
        $db = $this->dbTable($t->getName());

        if (!empty($ids)) {
            $db = $db->findMany(explode(',', $ids));
        }

        $html = $this->headerTable($id);

        $structures = $structures->where('table_id', $id);

        $fields         = ['id'];
        $labels         = ['id'];
        $types          = ['int'];
        $defaults       = ['null'];
        $typeFields     = ['id' => 'int'];
        $defaultFields  = ['id' => 'null'];

        foreach ($structures as $structure) {
            $s                      = $structure;
            $label                  = $s->getLabel();
            $field                  = $fieldDb->find($s->field_id)->getName();
            $fields[]               = $field;
            $labels[]               = !strlen($label) ? $field : $label;
            $types[]                = $s->getType();
            $defaults[]             = $s->getDefault();
            $typeFields[$field]     = $s->getType();
            $defaultFields[$field]  = $s->getDefault();
        }

        $page       = $request->get('page', 1);
        $limit      = $request->get('limit', 25);
        $order      = $request->get('order', 'id');
        $direction  = Str::upper($request->get('direction', 'ASC'));
        $query      = $request->get('query', 'id > 0');
        $isSearch   = false;

        if (strstr($query, '%%')) {
            $isSearch   = true;

            [$fieldsSeach, $operators, $values] = explode('%%', $query, 3);

            if (strstr($fieldsSeach, '##')) {
                $searchFields       = explode('##', $fieldsSeach);
                $searchOperators    = explode('##', $operators);
                $searchValues       = explode('##', $values);
                $searchQuery = '';

                foreach ($searchFields as $k => $searchField) {
                    $searchOperator = $searchOperators[$k];
                    $searchValue    = $searchValues[$k];

                    if (strlen($searchQuery)) {
                        $searchQuery .= ' && ';
                    }

                    $typeSearch     = $typeFields[$searchField];

                    if ($typeSearch == 'set') {
                        $defaultSearch = $defaultFields[$searchField];
                        $tab = explode(',', $defaultSearch);

                        foreach ($tab as $k => $rowSet) {
                            if (strstr($rowSet, '%%')) {
                                [$k, $v] = explode('%%', $rowSet, 2);
                            } else {
                                $v = $rowSet;
                            }

                            if (Str::lower($v) === Str::lower($searchValue)) {
                                $searchValue = $k;
                                break;
                            }
                        }
                    }

                    $searchQuery .= "$searchField $searchOperator $searchValue";
                }
            } else {
                $typeSearch  = $typeFields[$fieldsSeach];

                if ($typeSearch === 'set') {
                    $defaultSearch = $defaultFields[$fieldsSeach];
                    $tab = explode(',', $defaultSearch);

                    foreach ($tab as $k => $rowSet) {
                        if (strstr($rowSet, '%%')) {
                            [$k, $v] = explode('%%', $rowSet, 2);
                        } else {
                            $v = $rowSet;
                        }

                        if (Str::lower($v) === Str::lower($values)) {
                            $values = $k;
                            break;
                        }
                    }
                }

                $searchQuery = "$fieldsSeach $operators $values";
            }
        } else {
            $searchQuery = $query;
        }

        $export = $request->get('export');

        $offset = ($limit * $page) - $limit;
        $firstRow = $offset + 1;

        $sql = !$db instanceof RedisSQLCollection ? $this->query($db, $searchQuery) : $db;

        $results = 'ASC' === $direction ? $sql->sortBy($order) : $sql->sortByDesc($order);

        if (1 == $export) {
            $this->excel($results, $t->getName());
        } elseif ('pdf' === $export) {
            return $this->pdf($results, $t->getName());
        }

        $total      = $results->count();
        $lastRow    = $offset + $limit;

        if ($total < $lastRow) {
            $lastRow = $total;
        }

        $path = $this->r('displayData', ['id' => $request->get('id')]);

        $paginator = RedisSQLUtils::paginator(
            $res = $results->forPage($page, $limit)->toArray(),
            $total,
            $limit,
            $page,
            compact('path')
        );

        $pagination = $paginator->links('rsql::paginator');

        $html .= '<form id="listForm" action="' . $path . '" method="post">';
        $html .= '<input type="hidden" name="page" id="page" value="' . $page . '">';
        $html .= '<input type="hidden" name="limit" id="limit" value="' . $limit . '">';
        $html .= '<input type="hidden" name="order" id="order" value="' . $order . '">';
        $html .= '<input type="hidden" name="direction" id="direction" value="' . $direction . '">';
        $html .= '<input type="hidden" name="query" id="query" value="' . $query . '">';
        $html .= '<input type="hidden" name="export" id="export" value="0">';
        $html .= '</form>';

        if (empty($res) && !$isSearch) {
            $html .= '<div class="alert alert-info">No data yet.</div>';
            $content = $html;

            return view('rsql::content', compact('content', 'h1'));
        } elseif (empty($res) && $isSearch) {
            $html .= '<div class="alert alert-danger">
                    The search has no result.
                    <p><span onclick="selfPage();" class="link"><i class="fa fa-trash-o"></i> Reset this search</span></p>
                </div>';

            $content = $html;

            return view('rsql::content', compact('content', 'h1'));
        }

        if (false === $isSearch) {
            $html .= '<h3 class="link" onclick="showHide(\'searchContainer\');"><i class="fa fa-search"></i> <u>Search</u><h3>';
            $html .= '<div style="display: none;" id="searchContainer">';
            $html .= '<div id="search">';
            $html .= '<div id="rowSsearch">';
            $select = '<select class="fields" id="fields[]">';

            foreach ($fields as $k => $field) {
                $label = $labels[$k];
                $select .= '<option value="' . $field . '">' . $label . '</option>';
            }

            $select .= '</select>';

            $operator = '<select class="operators" id="operators[]">';
            $operator .= '<option value="=">=</option>';
            $operator .= '<option value="!==">!==</option>';
            $operator .= '<option value=">">&gt;</option>';
            $operator .= '<option value=">=">&gt;=</option>';
            $operator .= '<option value="<">&lt;</option>';
            $operator .= '<option value="<=">&lt;=</option>';
            $operator .= '<option value="LIKE">LIKE</option>';
            $operator .= '<option value="NOT LIKE">NOT LIKE</option>';
            $operator .= '<option value="LIKE START">STARTS WITH</option>';
            $operator .= '<option value="LIKE END">ENDS WITH</option>';
            $operator .= '<option value="IN">IN</option>';
            $operator .= '<option value="NOT IN">NOT IN</option>';
            $operator .= '</select>';
            $html .= $select
                . '&nbsp;'
                . $operator
                . '&nbsp;
                <input class="values" id="values[]" />
                &nbsp;
                <i title="Add a criteria" onclick="copyRow();" class="fa fa-plus link"></i>
                </div>
                </div>
                <button onclick="search();">GO</button>
                </div>';
        } else {
            $html .= '<p><span onclick="selfPage();" class="link"><i class="fa fa-trash-o"></i> Delete this search</span></p>';
        }

        $wordRecord = $total > 1 ? 'records' : 'record';

        $html .= '<p class="infos">' . $firstRow . ' to ' . $lastRow . ' on ' . $total . ' ' . $wordRecord . '</p>';
        $html .= $pagination;
        $uEdit = $this->r('editRecord', ['id' => 'id', 'table' => $request->get('id')]);
        $html .= '<script>const urlEdit = "' . $uEdit . '";</script>';
        $html .= '<table class="table">';

        foreach ($fields as $k => $field) {
            if ('foreign_label' !== $field) {
                $label = $labels[$k];
                $arrow = ($order === $field) ? ($direction === 'ASC' ? '&uarr;' : '&darr;') : '';
                $html .= '<th><span class="link" onclick="paginationOrder(\'' . $field . '\'); return false;">' . $label . ' ' . $arrow . '</span></th>';
            }
        }

        $html .= '<th>&nbsp;</th></tr>';
        $tabsComp = [];

        foreach ($res as $row) {
            $relations = [];

            foreach ($hasManies as $hasMany) {
                $relatedTable = $this->dbTable($hasMany);
                $relations[$hasMany] = $relatedTable->where($t->getName() . '_id', $row->getId());
            }

            $html .= '<tr ondblclick="editRow(' . $row['id'] . ');">';

            foreach ($fields as $k => $field) {
                if ('foreign_label' === $field) {
                    continue;
                }

                $value = $row[$field] ?? null;
                $type = $types[$k];
                $def = $defaults[$k];

                if (is_string($value)) {
                    $longValue = $value;
                    $value = $this->truncate($value);
                } elseif (is_object($value)) {
                    if ($value instanceof DateTimeInterface) {
                        $value = $value->getTimestamp();
                    } else {
                        $value = '<i>Object</i>';
                    }
                }

                if (!empty($value) && strstr($type, 'fk_')) {
                    $fkTable = str_replace('fk_', '', $type);
                    $tableFk = $this->tableByValue($fkTable);
                    $fk = $this->dbTable($fkTable)->find($value);
                    $display = null;

                    if ($fk) {
                        $display = $fk->getForeignLabel() ??
                            $fk->getLabel() ??
                            $fk->getTitle() ??
                            $fk->getName() ??
                            $fk->getId();
                    }

                    if (!empty($fk)) {
                        $u = $this->r('editRecord', ['id' => $fk->getId(), 'table' => $tableFk->getId()]);
                        $value = '<a class="fk" target="_fk" href="' . $u . '">' .
                            $this->truncate($display) . '</a>';
                    }
                }

                if ($type === 'file') {
                    $tab        = explode('/', $longValue);
                    $name       = end($tab);
                    $isImage    = false;

                    if (strstr($name, '.')) {
                        $isImage = $this->isImage($name);
                    }

                    if (true === $isImage) {
                        $file = '<img rel="tooltip" title="download" style="height: 100px; width: 100px;" src="' . $longValue . '" alt="' . $field . '" />';
                    } else {
                        $file = $name;
                    }

                    $value = '<p><a href="' . $longValue . '" target="_blank">' . $file . '</a></p>';
                } elseif ('set' === $type) {
                    $tabComp = Arr::get($tabsComp, $field);

                    if (empty($tabComp)) {
                        $tab = explode(',', $def);

                        foreach ($tab as $k => $rowSet) {
                            if (strstr($rowSet, '%%')) {
                                [$k, $v] = explode('%%', $rowSet, 2);
                            } else {
                                $v = $rowSet;
                            }

                            $tabComp[$k] = $v;
                        }

                        $tabsComp[$field] = $tabComp;
                    }

                    $value = $tabComp[$value];
                } elseif ('timestamp' === $type) {
                    $value = "<i><small style='color: gold !important;'>" . date('d/m/Y H:i:s', (int) $value) . "</small></i>";
                } elseif ('date' === $type) {
                    $value = date('d/m/Y', $value);
                } elseif ('datetime' === $type) {
                    $value = date('d/m/Y H:i:s', strtotime($value));
                } elseif ('time' === $type) {
                    $value = date('H:i:s', strtotime($value));
                }

                $html .= '<td>' . $value . '</td>';
            }

            $edit = $this->r('editRecord', ['id' => $row['id'], 'table' => $request->get('id')]);
            $dup = $this->r('duplicateRecord', ['id' => $row['id'], 'table' => $request->get('id')]);
            $del = $this->r('deleteRecord', ['id' => $row['id'], 'table' => $request->get('id')]);

            $html .= '<td>';

            foreach ($relations as $hasMany => $relation) {
                if ($relation->isEmpty()) {
                    continue;
                }

                $tr = $this->tables()->firstWhereName($hasMany);
                $html .= '<a class="linkRelation" target="_relations" 
                href="' . $this->r('displayData', ['id' => $tr->getId()]) . '&ids='.$relation->pluck('id')->implode(',')
                    .'">' . RedisSQLUtils::pluralize($hasMany) . '</a> | ';
            }

            $html .= '<a href="' . $edit . '"><i rel="tooltip" title="Edit record" class="fa fa-edit"></i></a> |
            <a href="' . $dup . '"><i rel="tooltip" title="Duplicate record" class="fa fa-copy"></i></a> |
            <a href="' . $del . '"><i rel="tooltip" title="Delete record" class="fa fa-trash-o"></i></a>';
            $html .= '</td>
            </tr>';
        }

        $html .= '</table><br><br>
        <span class="link yellow" onclick="makeExport();" >
        <i rel="tooltip" title="Export to Excel" class="fa fa-file-excel-o fa-2x"></i>
        </span>';
        $html .= '<span class="link yellow" onclick="makeExportPdf();" >
        <i class="fa fa-file-pdf-o fa-2x" rel="tooltip" title="Export to PDF"></i>
        </span>';
        $html .= $pagination;

        $content = $html;

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    public function doDuplicateRecord()
    {
        $request = request();

        if (!$table = $this->tables()->find($idTable = $request->get('table'))) {
            return $this->showError();
        }

        $structures = $this->structures()->whereTableId($idTable);
        $db = $this->dbTable($table->getName());

        if (!$record = $db->find($request->get('id'))) {
            return $this->showError('Record not found !!');
        }

        if (RedisSQLUtils::isPost()) {
            unset($_POST['MAX_FILE_SIZE']);

            $record = $db->create();
            $this->makeUpload($record, $table, $request);

            $record->hydrate();

            $this->redirect($this->r('displayData', ['id' => $idTable]));
        }

        $html = $this->headerTable($table->getId());

        $h1 = 'Duplicate record ' . $record->getId();

        $urlForm = $this->r('duplicateRecord', ['table' => $idTable, 'id' => $record->getId()]);

        $html .= '<form action="' . $urlForm . '" method="post" id="duplicateRecord" enctype="multipart/form-data">
            <input type="hidden" name="MAX_FILE_SIZE" value="100000000" />
            <table class="table">';
        foreach ($structures as $structure) {
            $field      = $this->fields()->find($structure->getFieldId());
            $field      = $field->getName();
            $label      = $structure->getLabel();
            $label      = !strlen($label) ? $field : $label;
            $type       = $structure->getType();
            $length     = $structure->getLength();
            $required   = $structure->getCanBeNull() ? false : true;
            $input      = $this->inputForm($type, $field, $length, $required, $record->$field, $structure);

            $html .= '<tr>
                <th>' . $label . '</th>
                <td>' . $input . '</td>
                </tr>';
        }

        $html .= '<tr><td>&nbsp;</td><td>
        <button onclick="$(\'#duplicateRecord\').submit();">OK</button>
        <a href="' . $this->r('displayData', ['id' => $idTable]) . '" class="btn btn-warning"><i class="fa fa-arrow-left"></i> Back</a>
        </td></tr>';
        $html .= '</table></form>';

        $content = $html;
        $title = 'Duplicate record ' . $record->getId();

        return view('rsql::content', compact('content', 'h1', 'title'));
    }

    protected function query(RedisSQL $db, string $condition)
    {
        if (!strstr($condition, ' && ')) {
            return $db->where(...explode(' ', $condition, 3));
        }

        foreach (explode(' && ', $condition) as $q) {
            $db = $db->where(...explode(' ', $q, 3));
        }

        return $db;
    }

    private function goHome()
    {
        $this->redirect($this->r('home'));
    }

    private function redirect(string $url)
    {
        header('Location: ' . $url);

        exit;
    }

    private function isImage(string $file): bool
    {
        $exts = ['jpg', 'jpeg', 'gif', 'png', 'bmp', 'svg', 'tiff'];

        if (strstr($file, '.')) {
            return in_array(Str::lower(Arr::last(explode('.', $file))), $exts);
        }

        return false;
    }

    private function tableByValue($name): ?RedisSQL
    {
        return $this->tables()->where('name', $name)->first();
    }

    private function checkBool(string $field): self
    {
        $bool = request($field);
        $_POST[$field] = empty($bool) ? 'false' : true;

        return $this;
    }

    private function selectTypes($structure = null, $table = null): string
    {
        if (!empty($structure)) {
            $table  = $structure->getTableId();
            $type   = $structure->getType();
        } else {
            if (!empty($table)) {
                $type = null;
            }
        }

        $select = '<select class="input-small" id="type" name="type">
            <optgroup label="Strings">
            <option ' . $this->selected($type, 'varchar') . '>varchar</option>
            <option ' . $this->selected($type, 'text') . '>text</option>
            <option ' . $this->selected($type, 'email') . '>email</option>
            <option ' . $this->selected($type, 'file') . '>file</option>
            <option ' . $this->selected($type, 'wysiwyg') . '>wysiwyg</option>
            <option ' . $this->selected($type, 'char') . '>char</option>
            <option ' . $this->selected($type, 'tinytext') . '>tinytext</option>
            <option ' . $this->selected($type, 'mediumtext') . '>mediumtext</option>
            <option ' . $this->selected($type, 'longtext') . '>longtext</option>
            </optgroup>
            <optgroup label="Numbers">
            <option ' . $this->selected($type, 'tinyint') . '>tinyint</option>
            <option ' . $this->selected($type, 'smallint') . '>smallint</option>
            <option ' . $this->selected($type, 'mediumint') . '>mediumint</option>
            <option ' . $this->selected($type, 'int') . '>int</option>
            <option ' . $this->selected($type, 'bigint') . '>bigint</option>
            <option ' . $this->selected($type, 'decimal') . '>decimal</option>
            <option ' . $this->selected($type, 'float') . '>float</option>
            <option ' . $this->selected($type, 'double') . '>double</option>
            </optgroup>
            <optgroup label="Date and time">
            <option ' . $this->selected($type, 'date') . '>date</option>
            <option ' . $this->selected($type, 'datetime') . '>datetime</option>
            <option ' . $this->selected($type, 'timestamp') . '>timestamp</option>
            <option ' . $this->selected($type, 'time') . '>time</option>
            <option ' . $this->selected($type, 'year') . '>year</option>
            </optgroup>';

        $fkTables = $this->tables()->where('id', '!==', $table)->sortByName();

        if (!$fkTables->isEmpty()) {
            $select .= '<optgroup label="Foreign keys">';

            foreach ($fkTables as $fkTable) {
                $select .= '<option value="fk_' . $fkTable['name'] . '" ' . $this->selected($type, 'fk_' . $fkTable['name']) . '>' . $fkTable['name'] . '</option>';
            }

            $select .= '</optgroup>';
        }

        $select .= '<optgroup label="Lists">
            <option ' . $this->selected($type, 'set') . '>set</option>
            </optgroup>
            <optgroup label="Binary">
            <option ' . $this->selected($type, 'bit') . '>bit</option>
            <option ' . $this->selected($type, 'binary') . '>binary</option>
            <option ' . $this->selected($type, 'varbinary') . '>varbinary</option>
            <option ' . $this->selected($type, 'tinyblob') . '>tinyblob</option>
            <option ' . $this->selected($type, 'blob') . '>blob</option>
            <option ' . $this->selected($type, 'mediumblob') . '>mediumblob</option>
            <option ' . $this->selected($type, 'longblob') . '>longblob</option>
            </optgroup>
            <optgroup label="Geometry">
            <option ' . $this->selected($type, 'geometry') . '>geometry</option>
            <option ' . $this->selected($type, 'point') . '>point</option>
            <option ' . $this->selected($type, 'linestring') . '>linestring</option>
            <option ' . $this->selected($type, 'polygon') . '>polygon</option>
            <option ' . $this->selected($type, 'multipoint') . '>multipoint</option>
            <option ' . $this->selected($type, 'multilinestring') . '>multilinestring</option>
            <option ' . $this->selected($type, 'multipolygon') . '>multipolygon</option>
            <option ' . $this->selected($type, 'geometrycollection') . '>geometrycollection</option>
            </optgroup>';

        $select .= '</optgroup></select>';

        return $select;
    }

    private function selected($value, $expected): string
    {
        if (!empty($value)) {
            if ($value == $expected) {
                return 'selected';
            }
        }

        return '';
    }
}
