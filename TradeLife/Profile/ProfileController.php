<?php
namespace TradeLife\Profile;
use Illuminate\Support\Facades\Response;  //Laravel Response class. Use response()->json()
use App\Http\Controllers\APIController;
use Illuminate\Support\Facades\Input;
use TradeLife\Profile\ProfileRepository;
use TradeLife\Stocks\StockController;


class ProfileController extends APIController
{
  protected $profileRepository;
  protected $stockController;
  function __construct(ProfileRepository $profileRepository, StockController $stockController)
  {
      $this->profileRepository = $profileRepository;
      $this->stockController = $stockController;
  }

  public function update()
  {
    $input = Input::json()->all();

    //return $this->profileRepository->testVersionTwo($input);
    return $this->profileRepository->generateProfile($input);
  }

  public function retrieve()
  {
    $input = Input::json()->all();
    return $this->profileRepository->retrieveProfile($input);
  }

  public function add()
  {
    $input = Input::json()->all();
    return $this->profileRepository->updateUserKeywords($input);
  }
}

?>
