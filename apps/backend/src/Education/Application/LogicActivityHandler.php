<?php

declare(strict_types=1);

namespace App\Education\Application;

/** Exercises and answer keys are generated and persisted exclusively on the server. */
final class LogicActivityHandler implements ActivityHandler
{
    public function validateConfig(array $config): array
    {
        ActivityHandlerRegistry::only($config,['language','difficulty','roundCount','minAccuracy','maxAttempts']);
        $out=array_replace(['language'=>'ru','difficulty'=>1,'roundCount'=>5,'minAccuracy'=>60,'maxAttempts'=>20],$config);
        if (!in_array($out['language'],['ru','en'],true) || !in_array($out['roundCount'],[5,10],true)) throw new EducationError(422,'invalid_input');
        ActivityHandlerRegistry::integer($out['difficulty'],1,3); ActivityHandlerRegistry::integer($out['maxAttempts'],1,20);
        if ((!is_int($out['minAccuracy']) && !is_float($out['minAccuracy'])) || !is_finite((float)$out['minAccuracy']) || $out['minAccuracy']<0 || $out['minAccuracy']>100) throw new EducationError(422,'invalid_input');
        return $out;
    }

    public function createExercise(array $config, ?array $set = null): array
    {
        $random=new \Random\Randomizer(); $rounds=[]; $ru=$config['language']==='ru'; $difficulty=$config['difficulty'];
        for ($i=0;$i<$config['roundCount'];$i++) {
            $kind=['sequence','pattern','logic','order','algorithm'][$i%5];
            $base=$random->getInt(2,9); $step=$random->getInt(2,4)*$difficulty;
            switch ($kind) {
                case 'sequence':
                    $values=[$base,$base+$step,$base+2*$step,$base+3*$step]; $answer=$base+4*$step;
                    $prompt=($ru?'Продолжи последовательность: ':'Continue the sequence: ').implode(', ',$values).', ?';
                    $explanation=$ru?"Каждый следующий член больше на $step.":"Add $step to each number.";
                    break;
                case 'pattern':
                    $factor=$difficulty+1; $values=[$base,$base*$factor,$base*$factor*$factor]; $answer=$base*$factor**3;
                    $prompt=($ru?'Найди закономерность: ':'Find the pattern: ').implode(', ',$values).', ?';
                    $explanation=$ru?"Каждый раз умножаем на $factor.":"Multiply by $factor each time.";
                    break;
                case 'logic':
                    $answer=$ru?'Да':'Yes'; $no=$ru?'Нет':'No'; $unknown=$ru?'Недостаточно данных':'Not enough information';
                    $prompt=$ru?'Все роботы в этой комнате синие. Роби — робот в этой комнате. Роби синий?':'Every robot in this room is blue. Robi is a robot in this room. Is Robi blue?';
                    if ($difficulty===2) { $answer=$unknown; $prompt=$ru?'Все квадраты — фигуры. Эта фигура красная. Обязательно ли она квадрат?':'All squares are shapes. This shape is red. Must it be a square?'; }
                    if ($difficulty===3) { $answer=$no; $prompt=$ru?'Ни один круг не имеет углов. У фигуры есть угол. Может ли эта фигура быть кругом?':'No circle has corners. A shape has a corner. Can that shape be a circle?'; }
                    $explanation=$difficulty===2?($ru?'Из свойства фигуры не следует, что она квадрат.':'Being a shape does not imply being a square.')
                        :($difficulty===3?($ru?'Круг не имеет углов; у этой фигуры угол есть.':'A circle has no corners, but this shape has one.')
                        :($ru?'Свойство всех роботов комнаты относится и к Роби.':'The property of every robot in the room also applies to Robi.'));
                    $choices=[$ru?'Да':'Yes',$no,$unknown]; break;
                case 'order':
                    $answer=$base+$step*$difficulty;
                    $prompt=($ru?'Вычисли с учётом порядка действий: ':'Use the correct order of operations: ')."$base + $step × $difficulty = ?";
                    $explanation=$ru?'Сначала выполняем умножение, затем сложение.':'Multiply before adding.'; break;
                default:
                    $times=$difficulty+1; $answer=$base+$step*$times;
                    $prompt=$ru?"Робот начинает с числа $base и $times раза прибавляет $step. Какое число получится?":"A robot starts at $base and adds $step exactly $times times. What is the result?";
                    $explanation=$ru?"Прибавляем $step ровно $times раза: $answer.":"Add $step exactly $times times: $answer.";
            }
            if ($kind!=='logic') $choices=[$answer,$answer+$step,$answer-$step,$answer+$step+1];
            $choices=$random->shuffleArray(array_values(array_unique(array_map('strval',$choices))));
            $options=[];$correct='';
            foreach($choices as $index=>$label) { $id='o'.($index+1);$options[]=['id'=>$id,'label'=>$label];if($label===(string)$answer)$correct=$id; }
            $rounds[]=['id'=>'r'.($i+1),'kind'=>$kind,'prompt'=>$prompt,'options'=>$options,'correctOptionId'=>$correct,'explanation'=>$explanation];
        }
        return ['version'=>1,'rounds'=>$rounds];
    }

    public function publicExercise(array $attempt): array
    {
        $config=$this->config($attempt);
        $exercise=ActivityHandlerRegistry::json($attempt['exercisePayload']);
        return ['version'=>(int)($exercise['version']??1),'language'=>$config['language'],'difficulty'=>$config['difficulty'],
            'rounds'=>array_map(static fn(array $round):array=>array_intersect_key($round,array_flip(['id','kind','prompt','options'])),$exercise['rounds']??[])];
    }

    public function validateState(array $attempt, array $state): array
    {
        ActivityHandlerRegistry::only($state,['answers','currentRound']);
        $exercise=ActivityHandlerRegistry::json($attempt['exercisePayload']);
        $answers=$this->answers($exercise,$state['answers']??[],false);
        $current=ActivityHandlerRegistry::integer($state['currentRound']??0,0,count($exercise['rounds']));
        return ['answers'=>$answers,'currentRound'=>$current];
    }

    public function evaluate(array $attempt, array $input): array
    {
        ActivityHandlerRegistry::only($input,['answers']);
        $exercise=ActivityHandlerRegistry::json($attempt['exercisePayload']);
        $answers=$this->answers($exercise,$input['answers']??null,true);$byId=array_column($answers,'optionId','roundId');
        $correct=0;$rounds=[];
        foreach($exercise['rounds'] as $round) {
            $ok=$byId[$round['id']]===$round['correctOptionId']; if($ok)$correct++;
            $rounds[]=['roundId'=>$round['id'],'optionId'=>$byId[$round['id']],'correctOptionId'=>$round['correctOptionId'],'correct'=>$ok,'explanation'=>$round['explanation']];
        }
        $config=$this->config($attempt);$total=count($rounds);$accuracy=round($correct/$total*100,2);
        return ['type'=>'logic','version'=>1,'requestHash'=>hash('sha256',json_encode($answers,JSON_THROW_ON_ERROR)),
            'metrics'=>['language'=>$config['language'],'difficulty'=>$config['difficulty'],'correctAnswers'=>$correct,'totalRounds'=>$total,
                'errors'=>$total-$correct,'score'=>$correct*100,'accuracy'=>$accuracy,'passed'=>$accuracy>=$config['minAccuracy'],'rounds'=>$rounds],
            'evidence'=>['answers'=>$answers]];
    }

    private function config(array $attempt): array
    {
        $exercise = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        return $this->validateConfig($exercise['configSnapshot'] ?? ActivityHandlerRegistry::json($attempt['config'] ?? []));
    }

    private function answers(array $exercise,mixed $input,bool $complete):array
    {
        if(!is_array($input)||!array_is_list($input)||count($input)>count($exercise['rounds']??[]))throw new EducationError(422,'invalid_input');
        $rounds=array_column($exercise['rounds'],null,'id');$out=[];
        foreach($input as $answer) {
            if(!is_array($answer))throw new EducationError(422,'invalid_input');
            ActivityHandlerRegistry::only($answer,['roundId','optionId']);$round=$answer['roundId']??null;$option=$answer['optionId']??null;
            if(!is_string($round)||!isset($rounds[$round])||isset($out[$round])||!is_string($option)||!in_array($option,array_column($rounds[$round]['options'],'id'),true))throw new EducationError(422,'invalid_input');
            $out[$round]=['roundId'=>$round,'optionId'=>$option];
        }
        if($complete && count($out)!==count($rounds))throw new EducationError(422,'invalid_input');
        ksort($out,SORT_STRING);return array_values($out);
    }
}
