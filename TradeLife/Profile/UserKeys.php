<?php

  namespace Trader\Profile;
  use PDO;
  use App\Transaction;
  use Illuminate\Support\Facades\Response;  //Laravel Response class. Use response()->json()
  use App\Http\Controllers\APIController;
  use Illuminate\Support\Facades\Input;

  class UserKeys extends APIController
  {
    private $user;

    public function generate($user)
    {
      $this->user = $user;
      $fileName = env('USER_PROFILE_REPO') . $this->user . ".json";
      $saveUserKeys = null;
      if(file_exists($fileName)){
        $tempProfile = json_decode(file_get_contents($fileName), true);
        $saveUserKeys = $tempProfile['User_Keywords'];}


      $profile = $this->emptyProfile($this->user);
      $profile['UserName'] = $user;
      $profile['User_Keywords'] = $saveUserKeys;
      //No transactions for user yet. don't do anything
      $temp = Transaction::where('name', '=', $this->user)->count();
      if ($temp <= 0)
      {
        return false;
      }
      else{
        $profile['Cate_Keywords'] = $this->buildCategory();
        $profile['Desc_Keywords'] = $this->buildDescription();

        file_put_contents($fileName, json_encode($profile, JSON_FORCE_OBJECT));

        $fileName = env('USER_PROFILE_REPO') . 'DEBUG'. $this->user . ".json";
        file_put_contents($fileName, json_encode($profile));
      }
      return true;
    }

    public function emptyProfile($user){
      return (["UserName" => $user, "Data_Period" => env('DEFAULT_AMOUNT_OF_DAYS'), "Invest_Date" => NULL,"Cate_Keywords" => NULL ,
                  "User_Keywords" => NULL,
                  "Desc_Keywords" => NULL, "Target_Sectors" => NULL,
                  "Target_Companies" => NULL]);
    }
    /**
    * Used in conjunction with usort to sort list in descending order by money
    * spent; If tied, more hits go first
    *   @return int
    */
    private static function cmpVal($a, $b)
    {
        if ($a['Value'] == $b['Value']) {
            return ($a['Hits'] > $b['Hits']) ? -1 : 1;
        }
        return ($a['Value'] > $b['Value']) ? -1 : 1;
    }

    /**
    * Used in conjunction with usort to sort list in descending order by the
    * amount of hits; If tied, higher money spent goes first
    *   @return int
    */
    private static function cmpHit($a, $b)
    {
        if ($a['Hits'] == $b['Hits']) {
            return ($a['Value'] > $b['Value']) ? -1 : 1;
        }
        return ($a['Hits'] > $b['Hits']) ? -1 : 1;
    }

    /**
    * Calculate the percent of total spending that each keyword uses. This is
    * not wholly accurate as 3 columns of keywords with no duplicates makes this
    * somewhat useless
    *
    * @param Multidimensional Array, pass by reference
    */
    private function percents(&$arr)
    {
      $total = $this->totalSpending();
      foreach ($arr as $key => &$row) {
        $row['Percent'] = round((($row['Value'] / $total) * 100), 2);
      }
    }

    /**
    * Run through transaction history and get a running total of expenses
    *
    * @return double
    */
    private function totalSpending()
    {
      $trans = Transaction::where('name', '=', $this->user)->get();
      $runningTotal = 0.0;
      foreach ($trans as $row) {
        $runningTotal += $row['amount'];
      }
      return $runningTotal;
    }

    /**
    * Parses the given user transaction table in order to get keywords from the
    * given data.
    *
    * @param Multidimensional Array, pass by Reference
    * @param Multidimensional Array
    * @param String
    * @param String, Optional
    * @return Multidimensional Array
    */
    private function parse(&$kw, $bad_kw, $column, $delimiter = "/[\/,\n]+/")
    {

       // Requery/reset to top of list
        $trans = Transaction::where([['name', '=', $this->user], ['cat_type', '=', 'EXPENSE']])->get()->toArray();
        foreach ($trans as $row) // Check each row.
        {
          // Split the current cell string by given delmiters. $current is an
          // array
          $current = preg_split($delimiter, trim($row[$column]));
          foreach ($current as $key) // Check each split word
          {
            // Check if current candidate keyword matches the list of blocked
            // keyWords
            if($this->checkKeyword($bad_kw, $key))
            {
              if($this->checkKeyword($kw, $key)){ // New Keyword
                $kw[$key] = [ 'Name' => $key, 'Value'=> doubleval($row['amount']),
                          'Hits' => 1, 'Percent' => 0.0];}
              else { // Keyword already in list. update values
                $kw[$key]['Value'] += $row['amount'];
                $kw[$key]['Hits'] += 1;}
            }
          }
        }
        return $kw;
    }

    /**
    * Checks if the keyword candidate exists in the given array. If not found
    * right away, break the candidate by space and check again. Since php uses
    * a Key-Value array, This works like a hashmap
    *
    * @param Multidimensional Array
    * @param String
    * @return Boolean
    */
    private function checkKeyword($inputArray, $candidateKeyword)
    {
      if(array_key_exists(strtoupper($candidateKeyword), $inputArray)){
        return false; // Contained previous/bad keyword
      }
      else{
        $wordBySpace = explode(" ", $candidateKeyword);
        foreach($wordBySpace as $candidateBySpace){
          if(array_key_exists(strtoupper($candidateBySpace), $inputArray)){
            return false; // Contained previous/bad keyword
          }
        }
      }
      return true;
    }


    /**
    * Build an array of ignored keywords that will not be allowed into the user
    * keyword space. Returning a Multidimensional array that allows it to be
    * used in other functions.
    *
    * @return Multidimensional Array of Strings
    */
    private function buildIgnoreKeywords()
    {
      $contents = file_get_contents(__DIR__ . '/ignore_user_keywords.txt');
      $arr = preg_split('/\s+/', $contents);
      $bad_kw = array();
      foreach($arr as $value){
        $bad_kw[$value] = ['Name' => $value, 'Value'=> 0];}
      return $bad_kw;
    }


    /**
    * Parses all 3 columns (category, original desc, simple desc) and returns an
    * array containing all keywords.
    *
    * @return Multidimensional array of Strings
    */
    public function buildKeywords()
    {
      $categoryKW = $this->buildCategory();
      $descriptionKW = $this->buildDescription();
      $combinedKW = array_unique(array_merge_recursive($categoryKW, $descriptionKW), SORT_REGULAR);
      usort($combinedKW, array($this, 'cmpVal'));
      return $combinedKW;
    }

    /**
    * Parses only the category column of the transaction database to build
    * keywords.
    *
    * @return Multidimensional array of Strings
    */
    public function buildCategory()
    {
      $bad_kw = $this->buildIgnoreKeywords();
      $kw_cat = array();

      $this->parse($kw_cat, $bad_kw, 'category');
      // usort($kw_cat, array($this, 'cmpVal'));
      //$this->percents($kw_cat);
      return $kw_cat;
    }

    /**
    * Parses both of the description columns in the transaction database to
    * build the keyword array.
    *
    * @return Multidimensional array of Strings
    */
    public function buildDescription()
    {
      $bad_kw = $this->buildIgnoreKeywords();
      $kw_simp = array();
      $kw_orig = array();

      $this->parse($kw_simp, $bad_kw, 'simple_desc');
      //$this->parse($kw_orig, $bad_kw, 'original_desc', "/[\/,\n,\s]+/");

      // $kw_desc = array_unique(array_merge($kw_orig, $kw_simp), SORT_REGULAR);
      // usort($kw_desc, array($this, 'cmpVal'));
      // $this->percents($kw_desc);

      return $kw_simp;
    }

    /**
    * Used to test if file was accessible.
    */
    public function test()
    {
      echo "PROFILE.php: Hello";
    }
  }
?>
